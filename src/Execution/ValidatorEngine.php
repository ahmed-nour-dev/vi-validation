<?php

declare(strict_types=1);

namespace Vi\Validation\Execution;

use Vi\Validation\Messages\MessageResolver;
use Vi\Validation\Rules\NullableRule;
use Vi\Validation\Rules\RuleInterface;

final class ValidatorEngine
{
    private ?MessageResolver $messageResolver;
    private bool $failFast;
    private int $maxErrors;
    private ErrorMode $errorMode = ErrorMode::All;

    private ?ErrorCollector $errors = null;
    private ?ValidationContext $context = null;

    private ?\Vi\Validation\Rules\DatabaseValidatorInterface $databaseValidator = null;
    private ?\Vi\Validation\Rules\PasswordHasherInterface $passwordHasher = null;

    public function __construct(
        ?MessageResolver $messageResolver = null,
        bool $failFast = false,
        int $maxErrors = 100,
        ErrorMode $errorMode = ErrorMode::All
    ) {
        $this->messageResolver = $messageResolver ?? new MessageResolver();
        $this->failFast = $failFast;
        $this->maxErrors = $maxErrors;
        $this->errorMode = $errorMode;
    }

    /**
     * @param CompiledSchema $schema
     * @param array<string, mixed> $data
     */
    public function validate(CompiledSchema $schema, array $data): ValidationResult
    {
        if ($this->errors === null) {
            $this->errors = new ErrorCollector();
            $this->context = new ValidationContext($data, $this->errors);
        } else {
            $this->errors->reset();
            if ($this->context !== null) {
                $this->context->setData($data);
            } else {
                $this->context = new ValidationContext($data, $this->errors);
            }
        }

        /** @var ValidationContext $context */
        $context = $this->context;
        $context->setDatabaseValidator($this->databaseValidator);
        $context->setPasswordHasher($this->passwordHasher);

        $errors = $this->errors;
        $errors->setCountOnly($this->errorMode === ErrorMode::CountOnly);
        $firstPerField = $this->errorMode === ErrorMode::FirstPerField;
        $excludedFields = [];

        try {
            foreach ($schema->getFields() as $field) {
                if ($this->shouldStopValidation($errors)) {
                    break;
                }

                $name = $field->getName();

                // Handle exclusion rules
                if ($field->shouldExclude($context)) {
                    $excludedFields[] = $name;
                    continue;
                }

                // Handle 'sometimes' rule: skip if field is not present in data
                if ($field->isSometimes() && !$context->hasValue($name)) {
                    continue;
                }

                $value = $field->getValue($data);
                $rules = $field->getRules();
                $isNullable = $field->isNullable();

                if ($value === null && $isNullable) {
                    continue;
                }

                // Laravel semantics: null, [], and strings that are empty after trim() are "empty".
                $isEmpty = $value === null || $value === [] || (is_string($value) && ($value === ''
                    || (isset(Emptiness::BLANK_FIRST[$value[0]]) && Emptiness::isBlankString($value))));

                foreach ($rules as $rule) {
                    // Non-implicit rules should skip if the value is "empty"
                    if ($isEmpty && !$this->isImplicitRule($rule)) {
                        continue;
                    }

                    if ($this->applyRule($rule, $name, $value, $context)) {
                        // Handle 'bail' rule (or ErrorMode::FirstPerField): stop validating this
                        // field after its first failure
                        if ($firstPerField || $field->isBail()) {
                            break;
                        }

                        /** @phpstan-ignore-next-line */
                        if ($this->shouldStopValidation($errors)) {
                            break;
                        }
                    }
                }
            }

            if ($errors->isCountOnly()) {
                return new ValidationResult([], $data, $this->messageResolver, $excludedFields, $errors->fieldCounts());
            }

            return new ValidationResult($errors->all(), $data, $this->messageResolver, $excludedFields);
        } finally {
            // The engine (and its reusable collector/context) outlives this call - in pooled or
            // long-running workers, across requests. Drop everything row-specific now, even if a
            // rule threw, so no data or partial errors survive into the next validation or stay
            // reachable (e.g. a sensitive request payload) while the worker idles. The returned
            // ValidationResult holds its own copies.
            $errors->reset();
            $context->setData([]);
        }
    }

    /**
     * Apply this engine's error policy (error mode, fail-fast, max errors) to a complete error
     * list produced elsewhere - the native path, whose generated closure always evaluates every
     * rule. Because the engine stops at exactly these points, the result is identical to what
     * the engine itself would have collected.
     *
     * @param array<string, list<array{rule: string, params: array<string, mixed>, message: string|null}>> $errors
     * @return array{0: array<string, list<array{rule: string, params: array<string, mixed>, message: string|null}>>, 1: array<string, int>|null}
     *         [errors, per-field counts for ErrorMode::CountOnly (errors is then empty) or null]
     */
    public function shapeErrors(array $errors): array
    {
        if ($errors === []) {
            return [[], $this->errorMode === ErrorMode::CountOnly ? [] : null];
        }

        $limit = $this->maxErrors;
        if ($this->failFast || $this->errorMode === ErrorMode::FirstPerRow) {
            $limit = min($limit, 1);
        }
        $firstPerField = $this->errorMode === ErrorMode::FirstPerField;

        $shaped = [];
        $total = 0;
        foreach ($errors as $field => $fieldErrors) {
            if ($total >= $limit) {
                break;
            }
            foreach ($fieldErrors as $error) {
                $shaped[$field][] = $error;
                $total++;
                if ($firstPerField || $total >= $limit) {
                    break;
                }
            }
        }

        if ($this->errorMode === ErrorMode::CountOnly) {
            return [[], array_map('count', $shaped)];
        }

        return [$shaped, null];
    }

    public function setErrorMode(ErrorMode $mode): void
    {
        $this->errorMode = $mode;
    }

    public function getErrorMode(): ErrorMode
    {
        return $this->errorMode;
    }

    public function isFailFast(): bool
    {
        return $this->failFast;
    }

    public function getMaxErrors(): int
    {
        return $this->maxErrors;
    }

    public function getMessageResolver(): ?MessageResolver
    {
        return $this->messageResolver;
    }

    /**
     * Snapshot of every configurable setting, for restoring an engine to a known state when
     * it is handed to the next request/job (see StatelessValidator / ValidatorPool).
     *
     * @return array{failFast: bool, maxErrors: int, errorMode: ErrorMode, messageResolver: ?MessageResolver, databaseValidator: ?\Vi\Validation\Rules\DatabaseValidatorInterface, passwordHasher: ?\Vi\Validation\Rules\PasswordHasherInterface}
     */
    public function exportSettings(): array
    {
        return [
            'failFast' => $this->failFast,
            'maxErrors' => $this->maxErrors,
            'errorMode' => $this->errorMode,
            'messageResolver' => $this->messageResolver,
            'databaseValidator' => $this->databaseValidator,
            'passwordHasher' => $this->passwordHasher,
        ];
    }

    /**
     * @param array{failFast: bool, maxErrors: int, errorMode: ErrorMode, messageResolver: ?MessageResolver, databaseValidator: ?\Vi\Validation\Rules\DatabaseValidatorInterface, passwordHasher: ?\Vi\Validation\Rules\PasswordHasherInterface} $settings
     */
    public function importSettings(array $settings): void
    {
        $this->failFast = $settings['failFast'];
        $this->maxErrors = $settings['maxErrors'];
        $this->errorMode = $settings['errorMode'];
        $this->messageResolver = $settings['messageResolver'];
        $this->databaseValidator = $settings['databaseValidator'];
        $this->passwordHasher = $settings['passwordHasher'];
    }

    public function setFailFast(bool $failFast): void
    {
        $this->failFast = $failFast;
    }

    public function setMaxErrors(int $maxErrors): void
    {
        $this->maxErrors = $maxErrors;
    }

    public function setMessageResolver(MessageResolver $resolver): void
    {
        $this->messageResolver = $resolver;
    }

    public function setDatabaseValidator(?\Vi\Validation\Rules\DatabaseValidatorInterface $validator): void
    {
        $this->databaseValidator = $validator;
    }

    public function setPasswordHasher(?\Vi\Validation\Rules\PasswordHasherInterface $hasher): void
    {
        $this->passwordHasher = $hasher;
    }



    private function isImplicitRule(RuleInterface $rule): bool
    {
        if ($rule instanceof \Vi\Validation\Laravel\LaravelRuleAdapter) {
            return $rule->isImplicit();
        }

        $class = get_class($rule);
        return in_array($class, [
            \Vi\Validation\Rules\RequiredRule::class,
            \Vi\Validation\Rules\RequiredIfRule::class,
            \Vi\Validation\Rules\RequiredUnlessRule::class,
            \Vi\Validation\Rules\RequiredWithRule::class,
            \Vi\Validation\Rules\RequiredWithAllRule::class,
            \Vi\Validation\Rules\RequiredWithoutRule::class,
            \Vi\Validation\Rules\RequiredWithoutAllRule::class,
            \Vi\Validation\Rules\RequiredIfAcceptedRule::class,
            \Vi\Validation\Rules\AcceptedRule::class,
            \Vi\Validation\Rules\AcceptedIfRule::class,
            \Vi\Validation\Rules\DeclinedRule::class,
            \Vi\Validation\Rules\DeclinedIfRule::class,
            \Vi\Validation\Rules\FilledRule::class,
            \Vi\Validation\Rules\PresentRule::class,
            \Vi\Validation\Rules\ProhibitedRule::class,
            \Vi\Validation\Rules\ProhibitedIfRule::class,
            \Vi\Validation\Rules\ProhibitedUnlessRule::class,
            \Vi\Validation\Rules\PresentIfRule::class,
            \Vi\Validation\Rules\PresentUnlessRule::class,
            \Vi\Validation\Rules\PresentWithRule::class,
            \Vi\Validation\Rules\PresentWithAllRule::class,
            \Vi\Validation\Rules\ProhibitedIfAcceptedRule::class,
            \Vi\Validation\Rules\ProhibitedIfDeclinedRule::class,
            \Vi\Validation\Rules\RequiredIfDeclinedRule::class,
        ], true);
    }

    private function shouldStopValidation(ErrorCollector $errors): bool
    {
        if (($this->failFast || $this->errorMode === ErrorMode::FirstPerRow) && $errors->hasErrors()) {
            return true;
        }

        if ($errors->count() >= $this->maxErrors) {
            return true;
        }

        return false;
    }

    /**
     * @param RuleInterface $rule
     */
    private function applyRule(RuleInterface $rule, string $field, mixed $value, ValidationContext $context): bool
    {
        $error = $rule->validate($value, $field, $context);

        if ($error !== null) {
            $params = (array) ($error['parameters'] ?? $error['params'] ?? []);
            $context->addError($field, (string)$error['rule'], $error['message'] ?? null, $params);
            return true;
        }

        return false;
    }
}
