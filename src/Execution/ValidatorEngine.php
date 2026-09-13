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

    private ?ErrorCollector $errors = null;
    private ?ValidationContext $context = null;

    private ?\Vi\Validation\Rules\DatabaseValidatorInterface $databaseValidator = null;
    private ?\Vi\Validation\Rules\PasswordHasherInterface $passwordHasher = null;

    public function __construct(
        ?MessageResolver $messageResolver = null,
        bool $failFast = false,
        int $maxErrors = 100
    ) {
        $this->messageResolver = $messageResolver ?? new MessageResolver();
        $this->failFast = $failFast;
        $this->maxErrors = $maxErrors;
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
        $excludedFields = [];

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

            $isEmpty = ($value === null || (is_string($value) && $value === '') || (is_array($value) && $value === []));

            foreach ($rules as $rule) {
                // Non-implicit rules should skip if the value is "empty"
                if ($isEmpty && !$this->isImplicitRule($rule)) {
                    continue;
                }

                if ($this->applyRule($rule, $name, $value, $context)) {
                    // Handle 'bail' rule: stop validating this field after first failure
                    if ($field->isBail()) {
                        break;
                    }

                    /** @phpstan-ignore-next-line */
                    if ($this->shouldStopValidation($errors)) {
                        break;
                    }
                }
            }
        }

        return new ValidationResult($errors->all(), $data, $this->messageResolver, $excludedFields);
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
        ], true);
    }

    private function shouldStopValidation(ErrorCollector $errors): bool
    {
        if ($this->failFast && $errors->hasErrors()) {
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
