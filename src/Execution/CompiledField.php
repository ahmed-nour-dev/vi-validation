<?php

declare(strict_types=1);

namespace Vi\Validation\Execution;

use Vi\Validation\Rules\RuleInterface;
use Vi\Validation\Schema\FieldDefinition;

final class CompiledField
{
    private string $name;
    /** @var list<RuleInterface> */
    private array $rules;
    private bool $isNullable = false;
    private bool $isBail = false;
    private bool $isSometimes = false;
    private bool $isNested;
    private ?string $parentField = null;
    private ?string $childField = null;

    private bool $isAlwaysExcluded = false;
    /** @var list<RuleInterface> */
    private array $exclusionRules = [];

    /**
     * @param list<RuleInterface> $rules
     */
    private function __construct(string $name, array $rules)
    {
        $this->name = $name;

        if (strpos($name, '.') !== false) {
            $this->isNested = true;
            [$this->parentField, $this->childField] = explode('.', $name, 2);
        } else {
            $this->isNested = false;
        }

        // Deduplicate rules: Keep only one instance of rules with the same class if they don't have unique state
        // For simplicity now, we'll deduplicate by class for marker rules, 
        // but for others we might need more complex logic.
        $this->rules = $this->deduplicateAndReorderRules($rules);
    }

    /**
     * @param list<RuleInterface> $rules
     * @return list<RuleInterface>
     */
    private function deduplicateAndReorderRules(array $rules): array
    {
        $uniqueRules = [];
        $hasRequired = false;
        $hasNullable = false;
        $hasBail = false;

        $markerRules = [];
        $otherRules = [];

        foreach ($rules as $rule) {
            if ($rule instanceof \Vi\Validation\Rules\RequiredRule) {
                if (!$hasRequired) {
                    $hasRequired = true;
                    $markerRules[] = $rule;
                }
            } elseif ($rule instanceof \Vi\Validation\Rules\NullableRule) {
                if (!$hasNullable) {
                    $hasNullable = true;
                    $this->isNullable = true;
                    $markerRules[] = $rule;
                }
            } elseif ($rule instanceof \Vi\Validation\Rules\BailRule) {
                if (!$hasBail) {
                    $hasBail = true;
                    $this->isBail = true;
                    $markerRules[] = $rule;
                }
            } elseif ($rule instanceof \Vi\Validation\Rules\SometimesRule) {
                $this->isSometimes = true;
            } elseif ($rule instanceof \Vi\Validation\Rules\ExcludeRule) {
                $this->isAlwaysExcluded = true;
            } elseif (
                $rule instanceof \Vi\Validation\Rules\ExcludeIfRule ||
                $rule instanceof \Vi\Validation\Rules\ExcludeUnlessRule ||
                $rule instanceof \Vi\Validation\Rules\ExcludeWithRule ||
                $rule instanceof \Vi\Validation\Rules\ExcludeWithoutRule
            ) {
                $this->exclusionRules[] = $rule;
            } else {
                $otherRules[] = $rule;
            }
        }

        // Fast-fail order: Bail -> Required -> Nullable -> Others
        $finalRules = [];
        
        // Find markers in specific order
        $bailMarker = null;
        $requiredMarker = null;
        $nullableMarker = null;

        foreach ($markerRules as $rule) {
            if ($rule instanceof \Vi\Validation\Rules\BailRule) $bailMarker = $rule;
            if ($rule instanceof \Vi\Validation\Rules\RequiredRule) $requiredMarker = $rule;
            if ($rule instanceof \Vi\Validation\Rules\NullableRule) $nullableMarker = $rule;
        }

        if ($bailMarker) $finalRules[] = $bailMarker;
        if ($requiredMarker) $finalRules[] = $requiredMarker;
        if ($nullableMarker) $finalRules[] = $nullableMarker;

        return array_merge($finalRules, $otherRules);
    }

    public static function fromFieldDefinition(FieldDefinition $definition): self
    {
        return new self($definition->getName(), $definition->getRules());
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return list<RuleInterface>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    public function isNullable(): bool
    {
        return $this->isNullable;
    }

    public function isBail(): bool
    {
        return $this->isBail;
    }

    public function isSometimes(): bool
    {
        return $this->isSometimes;
    }

    /**
     * Whether this field carries `exclude`/`exclude_if`/`exclude_unless`/`exclude_with`/
     * `exclude_without` semantics. NativeCompiler has no inlined equivalent for these (the
     * marker rule is consumed here and never appears in getRules()), so it must treat any
     * such field as unsupported and fall back to ValidatorEngine rather than silently
     * ignoring the exclusion.
     */
    public function hasExclusionSemantics(): bool
    {
        return $this->isAlwaysExcluded || $this->exclusionRules !== [];
    }

    public function shouldExclude(ValidationContext $context): bool
    {
        if ($this->isAlwaysExcluded) {
            return true;
        }

        foreach ($this->exclusionRules as $rule) {
            if (method_exists($rule, 'shouldExclude') && $rule->shouldExclude($context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function getValue(array $data): mixed
    {
        if (!$this->isNested) {
            return $data[$this->name] ?? null;
        }

        $parent = $data[$this->parentField] ?? null;
        if (!is_array($parent)) {
            return null;
        }

        return $parent[$this->childField] ?? null;
    }
}
