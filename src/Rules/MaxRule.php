<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Compilation\NativeMinMaxCompiler;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::MAX)]
final class MaxRule implements RuleInterface, NumericAwareInterface, NativeCompilableInterface
{
    private int|float $max;
    private bool $isNumeric = false;

    public function __construct(int|float $max)
    {
        $this->max = $max;
    }

    public function setNumeric(bool $numeric): void
    {
        $this->isNumeric = $numeric;
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if (count($value) > $this->max) {
            return ['rule' => 'max', 'params' => ['type' => 'array', 'max' => $this->max]];
            }
            return null;
        }

        if (is_string($value)) {
            // If explicit numeric context is set and value is numeric, treat as number
            if ($this->isNumeric && is_numeric($value)) {
                if ($value > $this->max) {
                    return ['rule' => 'max', 'params' => ['type' => 'numeric', 'max' => $this->max]];
                }
                return null;
            }

            if (mb_strlen($value) > $this->max) {
                return ['rule' => 'max', 'params' => ['type' => 'string', 'max' => $this->max]];
            }
            return null;
        }

        if (is_int($value) || is_float($value)) {
            if ($value > $this->max) {
            return ['rule' => 'max', 'params' => ['type' => 'numeric', 'max' => $this->max]];
            }
        }

        return null;
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        return NativeMinMaxCompiler::compile($context, $this->max, $this->isNumeric, 'max', '>');
    }

    public function isImplicitForNative(): bool
    {
        return false;
    }
}
