<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use ReflectionClass;
use InvalidArgumentException;
use LogicException;

/**
 * Registry for validation rules to facilitate string-to-rule mapping.
 */
final class RuleRegistry
{
    /** @var array<string, class-string<RuleInterface>> */
    private array $rules = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<class-string<RuleInterface>, array{name: string, aliases: array<string>}> */
    private static array $metadataCache = [];

    /**
     * Register a single rule class by reading its #[RuleName] attribute.
     *
     * @param class-string<RuleInterface> $class
     */
    public function register(string $class): void
    {
        if (!isset(self::$metadataCache[$class])) {
            $reflection = new ReflectionClass($class);
            $attributes = $reflection->getAttributes(RuleName::class);

            if (empty($attributes)) {
                throw new InvalidArgumentException(sprintf(
                    'Class "%s" does not have the #[RuleName] attribute.',
                    $class
                ));
            }

            /** @var RuleName $attribute */
            $attribute = $attributes[0]->newInstance();

            self::$metadataCache[$class] = [
                'name' => $attribute->name instanceof RuleId ? $attribute->name->value : $attribute->name,
                'aliases' => $attribute->aliases,
            ];
        }

        $metadata = self::$metadataCache[$class];
        $name = $metadata['name'];

        // Safeguard: Check for duplicate rule name or collision with existing alias
        if (isset($this->rules[$name]) && $this->rules[$name] !== $class) {
            throw new LogicException(sprintf(
                'Rule name "%s" is already registered by class "%s". Conflict with "%s".',
                $name,
                $this->rules[$name],
                $class
            ));
        }

        if (isset($this->aliases[$name])) {
            $existingRuleName = $this->aliases[$name];
            $existingClass = $this->rules[$existingRuleName];
            
            if ($existingClass !== $class) {
                throw new LogicException(sprintf(
                    'Rule name "%s" is already registered as an alias for rule "%s" (%s). Conflict with "%s".',
                    $name,
                    $existingRuleName,
                    $existingClass,
                    $class
                ));
            }
        }

        $this->rules[$name] = $class;

        foreach ($metadata['aliases'] as $alias) {
            // Safeguard: Check for duplicate alias or collision with existing primary name
            if (isset($this->rules[$alias])) {
                $existingClass = $this->rules[$alias];

                if ($existingClass !== $class) {
                    throw new LogicException(sprintf(
                        'Alias "%s" is already registered as a primary rule name for class "%s". Conflict with rule "%s" (%s).',
                        $alias,
                        $existingClass,
                        $name,
                        $class
                    ));
                }
            }

            if (isset($this->aliases[$alias])) {
                $existingRuleName = $this->aliases[$alias];
                $existingClass = $this->rules[$existingRuleName];

                if ($existingClass !== $class) {
                    throw new LogicException(sprintf(
                        'Alias "%s" is already registered for rule "%s" (%s). Conflict with rule "%s" (%s).',
                        $alias,
                        $existingRuleName,
                        $existingClass,
                        $name,
                        $class
                    ));
                }
            }

            $this->aliases[$alias] = $name;
        }
    }

    /**
     * Register multiple rule classes.
     *
     * @param array<class-string<RuleInterface>> $classes
     */
    public function registerMany(array $classes): void
    {
        foreach ($classes as $class) {
            $this->register($class);
        }
    }

    /**
     * Check if a rule exists by name, alias, or RuleId.
     */
    public function has(string|RuleId $name): bool
    {
        if ($name instanceof RuleId) {
            $name = $name->value;
        }

        return isset($this->rules[$name]) || isset($this->aliases[$name]);
    }

    /**
     * Get the class string for a rule by name, alias, or RuleId.
     *
     * @return class-string<RuleInterface>|null
     */
    public function get(string|RuleId $name): ?string
    {
        if ($name instanceof RuleId) {
            $name = $name->value;
        }

        if (isset($this->rules[$name])) {
            return $this->rules[$name];
        }

        if (isset($this->aliases[$name])) {
            $primaryName = $this->aliases[$name];
            return $this->rules[$primaryName] ?? null;
        }

        return null;
    }

    /**
     * Get all registered rules.
     *
     * @return array<string, class-string<RuleInterface>>
     */
    public function all(): array
    {
        return $this->rules;
    }

    /**
     * Resolve a rule instance by name, alias, or RuleId.
     *
     * @throws InvalidArgumentException
     */
    public function resolve(string|RuleId $name): RuleInterface
    {
        $class = $this->get($name);

        if ($class === null) {
            $nameStr = $name instanceof RuleId ? $name->value : $name;
            throw new InvalidArgumentException("Unknown rule: $nameStr");
        }

        return new $class();
    }

    /**
     * Register all built-in rules.
     */
    public function registerBuiltInRules(): void
    {
        $this->registerMany([
            AcceptedIfRule::class,
            AcceptedRule::class,
            ActiveUrlRule::class,
            AfterOrEqualRule::class,
            AfterRule::class,
            AlphaDashRule::class,
            AlphaRule::class,
            AlphanumericRule::class,
            ArrayRule::class,
            AsciiRule::class,
            BailRule::class,
            BeforeOrEqualRule::class,
            BeforeRule::class,
            BetweenRule::class,
            BooleanRule::class,
            ClosureRule::class,
            ConditionalRule::class,
            ConfirmedRule::class,
            ContainsRule::class,
            CountryRule::class,
            CurrentPasswordRule::class,
            DateEqualsRule::class,
            DateFormatRule::class,
            DateRule::class,
            DecimalRule::class,
            DeclinedIfRule::class,
            DeclinedRule::class,
            DifferentRule::class,
            DigitsBetweenRule::class,
            DigitsRule::class,
            DimensionsRule::class,
            DistinctRule::class,
            DoesntEndWithRule::class,
            DoesntStartWithRule::class,
            EmailRule::class,
            EndsWithRule::class,
            EnumRule::class,
            ExcludeIfRule::class,
            ExcludeRule::class,
            ExcludeUnlessRule::class,
            ExcludeWithRule::class,
            ExcludeWithoutRule::class,
            ExistsRule::class,
            ExtensionsRule::class,
            FileRule::class,
            FilledRule::class,
            GreaterThanOrEqualRule::class,
            GreaterThanRule::class,
            HexColorRule::class,
            ImageRule::class,
            InArrayRule::class,
            InRule::class,
            IntegerTypeRule::class,
            IpRule::class,
            JsonRule::class,
            LanguageRule::class,
            LessThanOrEqualRule::class,
            LessThanRule::class,
            ListRule::class,
            LowercaseRule::class,
            MacAddressRule::class,
            MaxDigitsRule::class,
            MaxFileSizeRule::class,
            MaxRule::class,
            MimesRule::class,
            MimetypesRule::class,
            MinDigitsRule::class,
            MinFileSizeRule::class,
            MinRule::class,
            MissingIfRule::class,
            MissingRule::class,
            MissingUnlessRule::class,
            MissingWithAllRule::class,
            MissingWithRule::class,
            MultipleOfRule::class,
            NotInRule::class,
            NotRegexRule::class,
            NullableRule::class,
            NumericRule::class,
            PasswordRule::class,
            PresentIfRule::class,
            PresentRule::class,
            PresentUnlessRule::class,
            PresentWithAllRule::class,
            PresentWithRule::class,
            ProhibitedIfAcceptedRule::class,
            ProhibitedIfDeclinedRule::class,
            ProhibitedIfRule::class,
            ProhibitedRule::class,
            ProhibitedUnlessRule::class,
            ProhibitsRule::class,
            RegexRule::class,
            RequiredArrayKeysRule::class,
            RequiredIfAcceptedRule::class,
            RequiredIfDeclinedRule::class,
            RequiredIfRule::class,
            RequiredRule::class,
            RequiredUnlessRule::class,
            RequiredWithAllRule::class,
            RequiredWithRule::class,
            RequiredWithoutAllRule::class,
            RequiredWithoutRule::class,
            SameRule::class,
            SizeRule::class,
            SometimesRule::class,
            StartsWithRule::class,
            StringTypeRule::class,
            TimezoneRule::class,
            UlidRule::class,
            UniqueRule::class,
            UppercaseRule::class,
            UrlRule::class,
            UuidRule::class,
        ]);
    }
}
