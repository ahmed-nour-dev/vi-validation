<?php

declare(strict_types=1);

namespace Vi\Validation\Schema;

use Vi\Validation\Rules\RuleInterface;
use Vi\Validation\Rules\RuleId;
use Vi\Validation\Rules\InRule;
use Vi\Validation\Rules\NotInRule;
use Vi\Validation\Rules\MimesRule;
use Vi\Validation\Rules\MimetypesRule;
use Vi\Validation\Rules\DimensionsRule;
use Vi\Validation\Rules\EnumRule;
use Vi\Validation\Rules\PasswordRule;
use Vi\Validation\Rules\RequiredIfRule;
use Vi\Validation\Rules\RequiredUnlessRule;
use Vi\Validation\Rules\RequiredWithRule;
use Vi\Validation\Rules\RequiredWithAllRule;
use Vi\Validation\Rules\RequiredWithoutRule;
use Vi\Validation\Rules\RequiredWithoutAllRule;
use Vi\Validation\Rules\ProhibitedIfRule;
use Vi\Validation\Rules\ProhibitedUnlessRule;
use Vi\Validation\Rules\ProhibitsRule;
use Vi\Validation\Rules\StartsWithRule;
use Vi\Validation\Rules\EndsWithRule;
use Vi\Validation\Rules\ExistsRule;
use Vi\Validation\Rules\UniqueRule;
use Vi\Validation\Rules\StringTypeRule;
use Vi\Validation\Rules\IntegerTypeRule;

final class FieldDefinition
{
    private string $name;
    /** @var list<RuleInterface> */
    private array $rules = [];
    private bool $nullable = false;
    private ?SchemaBuilder $builder;

    public function __construct(string $name, ?SchemaBuilder $builder = null)
    {
        $this->name = $name;
        $this->builder = $builder;
    }

    public function field(string $name): FieldDefinition
    {
        if ($this->builder === null) {
            throw new \RuntimeException('FieldDefinition has no SchemaBuilder reference.');
        }
        return $this->builder->field($name);
    }

    public function compile(): \Vi\Validation\Execution\CompiledSchema
    {
        if ($this->builder === null) {
            throw new \RuntimeException('FieldDefinition has no SchemaBuilder reference.');
        }
        return $this->builder->compile();
    }

    public function end(): SchemaBuilder
    {
        if ($this->builder === null) {
            throw new \RuntimeException('FieldDefinition has no SchemaBuilder reference.');
        }
        return $this->builder;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /** @return list<RuleInterface> */
    public function getRules(): array
    {
        return $this->rules;
    }

    public function rules(RuleInterface ...$rules): self
    {
        foreach ($rules as $rule) {
            $this->rules[] = $rule;
        }
        return $this;
    }

    public function nullable(): self
    {
        $this->nullable = true;
        return $this;
    }

    public function required(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\RequiredRule();
        return $this;
    }

    public function string(): self
    {
        $this->rules[] = new StringTypeRule();
        return $this;
    }

    public function str(): self
    {
        return $this->string();
    }

    public function integer(): self
    {
        $this->rules[] = new IntegerTypeRule();
        return $this;
    }

    public function int(): self
    {
        return $this->integer();
    }

    public function numeric(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\NumericRule();
        return $this;
    }

    public function boolean(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\BooleanRule();
        return $this;
    }

    public function array(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ArrayRule();
        return $this;
    }

    public function email(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\EmailRule();
        return $this;
    }

    public function min(int|float $min): self
    {
        $this->rules[] = new \Vi\Validation\Rules\MinRule($min);
        return $this;
    }

    public function max(int|float $max): self
    {
        $this->rules[] = new \Vi\Validation\Rules\MaxRule($max);
        return $this;
    }

    public function size(int|float $size): self
    {
        $this->rules[] = new \Vi\Validation\Rules\SizeRule($size);
        return $this;
    }

    public function between(int|float $min, int|float $max): self
    {
        $this->rules[] = new \Vi\Validation\Rules\BetweenRule($min, $max);
        return $this;
    }

    public function decimal(int $min, ?int $max = null): self
    {
        $this->rules[] = new \Vi\Validation\Rules\DecimalRule($min, $max);
        return $this;
    }

    public function bool(): self
    {
        return $this->boolean();
    }

    public function list(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ListRule();
        return $this;
    }

    public function sometimes(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\SometimesRule();
        return $this;
    }

    public function same(string $field): self
    {
        $this->rules[] = new \Vi\Validation\Rules\SameRule($field);
        return $this;
    }

    public function different(string $field): self
    {
        $this->rules[] = new \Vi\Validation\Rules\DifferentRule($field);
        return $this;
    }

    /**
     * @param string $table
     * @param string $column
     * @param array<string, mixed> $extraConstraints
     * @param string|null $connection
     */
    public function exists(string $table, string $column = 'id', array $extraConstraints = [], ?string $connection = null): self
    {
        $this->rules[] = new ExistsRule($table, $column, $extraConstraints, $connection);
        return $this;
    }

    /**
     * @param string $table
     * @param string $column
     * @param mixed $ignoreId
     * @param string $idColumn
     * @param array<string, mixed> $extraConstraints
     * @param string|null $connection
     */
    public function unique(string $table, string $column = 'id', mixed $ignoreId = null, string $idColumn = 'id', array $extraConstraints = [], ?string $connection = null): self
    {
        $this->rules[] = new UniqueRule($table, $column, $ignoreId, $idColumn, $extraConstraints, $connection);
        return $this;
    }

    public function in(mixed ...$values): self
    {
        /** @var array<int, string> $castedValues */
        $castedValues = array_map('strval', array_values($values));
        $this->rules[] = new InRule($castedValues);
        return $this;
    }

    public function notIn(mixed ...$values): self
    {
        /** @var array<int, string> $castedValues */
        $castedValues = array_map('strval', array_values($values));
        $this->rules[] = new NotInRule($castedValues);
        return $this;
    }

    public function confirmed(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ConfirmedRule();
        return $this;
    }

    public function mimes(string ...$allowedMimes): self
    {
        /** @var array<int, string> $castedMimes */
        $castedMimes = array_values($allowedMimes);
        $this->rules[] = new MimesRule($castedMimes);
        return $this;
    }

    public function mimetypes(string ...$allowedTypes): self
    {
        /** @var array<int, string> $castedTypes */
        $castedTypes = array_values($allowedTypes);
        $this->rules[] = new MimetypesRule(...$castedTypes);
        return $this;
    }

    /**
     * @param array<string, mixed> $constraints
     */
    public function dimensions(array $constraints): self
    {
        $this->rules[] = new DimensionsRule($constraints);
        return $this;
    }

    public function extensions(string ...$extensions): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ExtensionsRule(...$extensions);
        return $this;
    }

    public function maxFileSize(int $kb): self
    {
        $this->rules[] = new \Vi\Validation\Rules\MaxFileSizeRule($kb);
        return $this;
    }

    public function minFileSize(int $kb): self
    {
        $this->rules[] = new \Vi\Validation\Rules\MinFileSizeRule($kb);
        return $this;
    }

    public function file(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\FileRule();
        return $this;
    }

    public function image(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ImageRule();
        return $this;
    }

    public function activeUrl(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ActiveUrlRule();
        return $this;
    }

    public function alpha(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\AlphaRule();
        return $this;
    }

    public function alphaDash(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\AlphaDashRule();
        return $this;
    }

    public function alphanumeric(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\AlphanumericRule();
        return $this;
    }

    public function country(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\CountryRule();
        return $this;
    }

    public function language(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\LanguageRule();
        return $this;
    }

    public function ascii(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\AsciiRule();
        return $this;
    }

    public function date(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\DateRule();
        return $this;
    }

    public function dateFormat(string $format): self
    {
        $this->rules[] = new \Vi\Validation\Rules\DateFormatRule($format);
        return $this;
    }

    public function dateEquals(string $date): self
    {
        $this->rules[] = new \Vi\Validation\Rules\DateEqualsRule($date);
        return $this;
    }

    public function json(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\JsonRule();
        return $this;
    }

    public function ipv4(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\IpRule('ipv4');
        return $this;
    }

    public function ipv6(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\IpRule('ipv6');
        return $this;
    }

    public function ip(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\IpRule();
        return $this;
    }

    public function url(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\UrlRule();
        return $this;
    }

    public function uuid(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\UuidRule();
        return $this;
    }

    public function regex(string $pattern): self
    {
        $this->rules[] = new \Vi\Validation\Rules\RegexRule($pattern);
        return $this;
    }

    public function notRegex(string $pattern): self
    {
        $this->rules[] = new \Vi\Validation\Rules\NotRegexRule($pattern);
        return $this;
    }

    public function doesntStartWith(string ...$needles): self
    {
        $this->rules[] = new \Vi\Validation\Rules\DoesntStartWithRule(...$needles);
        return $this;
    }

    public function doesntEndWith(string ...$needles): self
    {
        $this->rules[] = new \Vi\Validation\Rules\DoesntEndWithRule(...$needles);
        return $this;
    }

    public function digitsBetween(int $min, int $max): self
    {
        $this->rules[] = new \Vi\Validation\Rules\DigitsBetweenRule($min, $max);
        return $this;
    }

    public function requiredArrayKeys(string ...$keys): self
    {
        $this->rules[] = new \Vi\Validation\Rules\RequiredArrayKeysRule(...$keys);
        return $this;
    }

    public function startsWith(string ...$values): self
    {
        /** @var array<int, string> $castedValues */
        $castedValues = array_values($values);
        $this->rules[] = new StartsWithRule($castedValues);
        return $this;
    }

    public function endsWith(string ...$values): self
    {
        /** @var array<int, string> $castedValues */
        $castedValues = array_values($values);
        $this->rules[] = new EndsWithRule($castedValues);
        return $this;
    }

    public function timezone(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\TimezoneRule();
        return $this;
    }

    /**
     * @param class-string<\UnitEnum> $enumClass
     */
    public function enum(string $enumClass): self
    {
        $this->rules[] = new EnumRule($enumClass);
        return $this;
    }

    public function password(?callable $callback = null): PasswordRule
    {
        $rule = new PasswordRule();
        if ($callback !== null) {
            $callback($rule);
        }
        $this->rules[] = $rule;
        return $rule;
    }

    public function currentPassword(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\CurrentPasswordRule();
        return $this;
    }

    /**
     * @param array<int|string, mixed> $values
     */
    public function requiredIf(string $otherField, array $values): self
    {
        $this->rules[] = new RequiredIfRule($otherField, array_values($values));
        return $this;
    }

    public function requiredIfAccepted(string $otherField): self
    {
        $this->rules[] = new \Vi\Validation\Rules\RequiredIfAcceptedRule($otherField);
        return $this;
    }

    /**
     * @param array<int|string, mixed> $values
     */
    public function requiredUnless(string $otherField, array $values): self
    {
        $this->rules[] = new RequiredUnlessRule($otherField, array_values($values));
        return $this;
    }

    public function requiredWith(string ...$others): self
    {
        /** @var array<int, string> $othersList */
        $othersList = array_values($others);
        $this->rules[] = new RequiredWithRule($othersList);
        return $this;
    }

    public function requiredWithAll(string ...$others): self
    {
        /** @var array<int, string> $othersList */
        $othersList = array_values($others);
        $this->rules[] = new RequiredWithAllRule($othersList);
        return $this;
    }

    public function requiredWithout(string ...$others): self
    {
        /** @var array<int, string> $othersList */
        $othersList = array_values($others);
        $this->rules[] = new RequiredWithoutRule($othersList);
        return $this;
    }

    public function requiredWithoutAll(string ...$others): self
    {
        /** @var array<int, string> $othersList */
        $othersList = array_values($others);
        $this->rules[] = new RequiredWithoutAllRule($othersList);
        return $this;
    }

    public function present(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\PresentRule();
        return $this;
    }

    public function prohibited(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ProhibitedRule();
        return $this;
    }

    /**
     * @param array<int|string, mixed> $values
     */
    public function prohibitedIf(string $otherField, array $values): self
    {
        $this->rules[] = new ProhibitedIfRule($otherField, array_values($values));
        return $this;
    }

    /**
     * @param array<int|string, mixed> $values
     */
    public function prohibitedUnless(string $otherField, array $values): self
    {
        $this->rules[] = new ProhibitedUnlessRule($otherField, array_values($values));
        return $this;
    }

    public function acceptedIf(string $otherField, mixed $value): self
    {
        $this->rules[] = new \Vi\Validation\Rules\AcceptedIfRule($otherField, $value);
        return $this;
    }

    public function declinedIf(string $otherField, mixed $value): self
    {
        $this->rules[] = new \Vi\Validation\Rules\DeclinedIfRule($otherField, $value);
        return $this;
    }

    public function prohibitedWith(string ...$others): self
    {
        /** @var array<int, string> $othersList */
        $othersList = array_values($others);
        $this->rules[] = new ProhibitsRule(...$othersList);
        return $this;
    }

    /**
     * Alias for prohibitedWith().
     */
    public function prohibits(string ...$fields): self
    {
        return $this->prohibitedWith(...$fields);
    }

    public function missing(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\MissingRule();
        return $this;
    }

    public function missingIf(string $otherField, mixed $value): self
    {
        $this->rules[] = new \Vi\Validation\Rules\MissingIfRule($otherField, $value);
        return $this;
    }

    public function missingUnless(string $otherField, mixed $value): self
    {
        $this->rules[] = new \Vi\Validation\Rules\MissingUnlessRule($otherField, $value);
        return $this;
    }

    public function missingWith(string ...$others): self
    {
        $this->rules[] = new \Vi\Validation\Rules\MissingWithRule(...$others);
        return $this;
    }

    public function missingWithAll(string ...$others): self
    {
        $this->rules[] = new \Vi\Validation\Rules\MissingWithAllRule(...$others);
        return $this;
    }

    public function exclude(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ExcludeRule();
        return $this;
    }

    public function excludeIf(string $otherField, mixed $value): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ExcludeIfRule($otherField, $value);
        return $this;
    }

    public function excludeUnless(string $otherField, mixed $value): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ExcludeUnlessRule($otherField, $value);
        return $this;
    }

    public function excludeWith(string $otherField): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ExcludeWithRule($otherField);
        return $this;
    }

    public function excludeWithout(string $otherField): self
    {
        $this->rules[] = new \Vi\Validation\Rules\ExcludeWithoutRule($otherField);
        return $this;
    }

    public function multipleOf(int|float $value): self
    {
        $this->rules[] = new \Vi\Validation\Rules\MultipleOfRule($value);
        return $this;
    }

    public function macAddress(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\MacAddressRule();
        return $this;
    }

    public function uppercase(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\UppercaseRule();
        return $this;
    }

    public function lowercase(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\LowercaseRule();
        return $this;
    }

    public function ulid(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\UlidRule();
        return $this;
    }

    public function gt(string $otherField): self
    {
        $this->rules[] = new \Vi\Validation\Rules\GreaterThanRule($otherField);
        return $this;
    }

    public function gte(string $otherField): self
    {
        $this->rules[] = new \Vi\Validation\Rules\GreaterThanOrEqualRule($otherField);
        return $this;
    }

    public function lt(string $otherField): self
    {
        $this->rules[] = new \Vi\Validation\Rules\LessThanRule($otherField);
        return $this;
    }

    public function lte(string $otherField): self
    {
        $this->rules[] = new \Vi\Validation\Rules\LessThanOrEqualRule($otherField);
        return $this;
    }

    public function filled(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\FilledRule();
        return $this;
    }

    public function digits(int $digits): self
    {
        $this->rules[] = new \Vi\Validation\Rules\DigitsRule($digits);
        return $this;
    }

    public function distinct(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\DistinctRule();
        return $this;
    }

    public function bail(): self
    {
        $this->rules[] = new \Vi\Validation\Rules\BailRule();
        return $this;
    }

    public function when(mixed $condition, callable $onTrue, ?callable $onFalse = null): self
    {
        if (is_callable($condition)) {
            // Deferred evaluation using ConditionalRule
            $trueBuilder = new SchemaBuilder();
            $trueField = $trueBuilder->field($this->name);
            $onTrue($trueField);
            $trueRules = $trueField->getRules();

            $falseRules = [];
            if ($onFalse !== null) {
                $falseBuilder = new SchemaBuilder();
                $falseField = $falseBuilder->field($this->name);
                $onFalse($falseField);
                $falseRules = $falseField->getRules();
            }

            $this->rules[] = new \Vi\Validation\Rules\ConditionalRule($condition, $trueRules, $falseRules);
        } else {
            // Immediate evaluation
            if ($condition) {
                $trueBuilder = new SchemaBuilder();
                $trueField = $trueBuilder->field($this->name);
                $onTrue($trueField);
                $this->rules = array_merge($this->rules, $trueField->getRules());
            } elseif ($onFalse !== null) {
                $falseBuilder = new SchemaBuilder();
                $falseField = $falseBuilder->field($this->name);
                $onFalse($falseField);
                $this->rules = array_merge($this->rules, $falseField->getRules());
            }
        }

        return $this;
    }
}
