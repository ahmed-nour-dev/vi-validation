<?php

declare(strict_types=1);

namespace Vi\Validation\Laravel;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ImplicitRule;
use Illuminate\Contracts\Validation\InvokableRule;
use Illuminate\Contracts\Validation\Rule as LegacyRule;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Vi\Validation\Execution\ValidationContext;
use Vi\Validation\Rules\RuleInterface;

/**
 * Runs a Laravel rule object inside vi/validation:
 *
 * - `Illuminate\Contracts\Validation\ValidationRule` (Laravel 10+ `validate($attribute, $value, $fail)`),
 * - `Illuminate\Contracts\Validation\InvokableRule` (`__invoke($attribute, $value, $fail)`),
 * - `Illuminate\Contracts\Validation\Rule` (legacy `passes()` / `message()`).
 *
 * `DataAwareRule`s receive the row via setData() before each call. A rule is treated as
 * implicit (it runs even when the value is empty) when it implements `ImplicitRule` or
 * declares a public `$implicit = true` property, exactly like Laravel.
 *
 * Adapted rules always run in ValidatorEngine; they never make a schema natively compilable.
 */
final class LaravelRuleAdapter implements RuleInterface
{
    private static ?Translator $fallbackTranslator = null;

    public function __construct(private readonly object $rule)
    {
    }

    public static function supports(mixed $rule): bool
    {
        return $rule instanceof ValidationRule
            || $rule instanceof InvokableRule
            || $rule instanceof LegacyRule;
    }

    public function getRule(): object
    {
        return $this->rule;
    }

    public function isImplicit(): bool
    {
        return $this->rule instanceof ImplicitRule
            || (property_exists($this->rule, 'implicit') && ($this->rule->implicit ?? false) === true);
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($this->rule instanceof DataAwareRule) {
            $this->rule->setData($context->getData());
        }

        $messages = [];
        $fail = static function (string $attributeOrMessage, ?string $message = null) use (&$messages): PotentiallyTranslatedString {
            $text = $message ?? $attributeOrMessage;
            $messages[] = $text;

            return new PotentiallyTranslatedString($text, self::translator());
        };

        if ($this->rule instanceof ValidationRule) {
            $this->rule->validate($field, $value, $fail);
        } elseif ($this->rule instanceof InvokableRule) {
            ($this->rule)($field, $value, $fail);
        } elseif ($this->rule instanceof LegacyRule && !$this->rule->passes($field, $value)) {
            $message = $this->rule->message();
            $messages[] = is_array($message) ? (string) (reset($message) ?: '') : (string) $message;
        }

        if ($messages === []) {
            return null;
        }

        return [
            'rule' => $this->ruleName(),
            'message' => str_replace(':attribute', str_replace('_', ' ', $field), (string) $messages[0]),
        ];
    }

    /**
     * The application's translator inside Laravel (so `$fail('key')->translate()` resolves app
     * translations); otherwise an empty one, which returns keys unchanged.
     */
    private static function translator(): Translator
    {
        if (function_exists('app')) {
            try {
                $translator = app('translator');
                if ($translator instanceof Translator) {
                    return $translator;
                }
            } catch (\Throwable) {
                // not running inside a Laravel application
            }
        }

        return self::$fallbackTranslator ??= new \Illuminate\Translation\Translator(new ArrayLoader(), 'en');
    }

    private function ruleName(): string
    {
        $short = substr((string) strrchr('\\' . get_class($this->rule), '\\'), 1);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }
}
