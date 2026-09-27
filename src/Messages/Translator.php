<?php

declare(strict_types=1);

namespace Vi\Validation\Messages;

final class Translator implements TranslatorInterface
{
    private string $locale;

    /** @var array<string, array<string, string|array<string, string>>> */
    private array $messages = [];

    private string $fallbackLocale = 'en';

    private ?string $langPath = null;

    public function __construct(string $locale = 'en', ?string $langPath = null)
    {
        $this->locale = $locale;
        $this->langPath = $langPath;
        $this->loadDefaultMessages();
        
        if ($langPath !== null) {
            $this->loadFromPath($langPath, $locale);
        }
    }

    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->locale;

        if (!isset($this->messages[$locale])) {
            $this->loadBundledLocale($locale);
        }
        if (!isset($this->messages[$this->fallbackLocale])) {
            $this->loadBundledLocale($this->fallbackLocale);
        }

        $message = $this->getMessage($key, $locale);

        if ($message === null) {
            $message = $this->getMessage($key, $this->fallbackLocale);
        }

        if ($message === null) {
            return $key;
        }

        return $this->replaceAttributes($message, $replace);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
        
        // Try to load messages for the new locale
        if ($this->langPath !== null) {
            $this->loadFromPath($this->langPath, $locale);
        }
    }

    /**
     * @param array<string, string|array<string, string>> $messages
     */
    public function addMessages(array $messages, string $locale): void
    {
        if (!isset($this->messages[$locale])) {
            $this->messages[$locale] = [];
        }

        $this->messages[$locale] = array_merge($this->messages[$locale], $messages);
    }

    public function setFallbackLocale(string $locale): void
    {
        $this->fallbackLocale = $locale;
    }

    /**
     * Load messages from a language file.
     */
    public function loadFromPath(string $basePath, string $locale): void
    {
        $filePath = rtrim($basePath, '/\\') . '/' . $locale . '/validation.php';
        
        if (file_exists($filePath)) {
            $messages = require $filePath;
            if (is_array($messages)) {
                $this->addMessages($messages, $locale);
            }
        }
    }

    /**
     * Load the catalog this package ships for $locale (resources/lang/{locale}/validation.php),
     * e.g. Arabic, the first time that locale is used. Without this, only English (built in
     * above) worked unless a lang path was configured explicitly. A catalog from the configured
     * lang path, if any, takes precedence. Locales with no catalog are remembered as empty so
     * the lookup happens once.
     */
    private function loadBundledLocale(string $locale): void
    {
        $this->messages[$locale] ??= [];

        if (preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
            return;
        }

        $this->loadFromPath(dirname(__DIR__, 2) . '/resources/lang', $locale);

        if ($this->langPath !== null) {
            $this->loadFromPath($this->langPath, $locale);
        }
    }

    /**
     * Set the path where language files are located.
     */
    public function setLangPath(string $path): void
    {
        $this->langPath = $path;
    }

    private function getMessage(string $key, string $locale): ?string
    {
        $messages = $this->messages[$locale] ?? [];

        // Handle nested keys like 'min.string'
        $parts = explode('.', $key);

        if (count($parts) === 1) {
            $message = $messages[$key] ?? null;
            return is_string($message) ? $message : null;
        }

        $current = $messages;
        foreach ($parts as $part) {
            if (!is_array($current) || !isset($current[$part])) {
                return null;
            }
            $current = $current[$part];
        }

        return is_string($current) ? $current : null;
    }

    /**
     * @param array<string, string> $replace
     */
    private function replaceAttributes(string $message, array $replace): string
    {
        foreach ($replace as $key => $value) {
            $message = str_replace(
                [':' . $key, ':' . strtoupper($key), ':' . ucfirst($key)],
                [$value, strtoupper($value), ucfirst($value)],
                $message
            );
        }

        return $message;
    }

    private function loadDefaultMessages(): void
    {
        $this->messages['en'] = [
            'required' => 'The :attribute field is required.',
            'string' => 'The :attribute must be a string.',
            'integer' => 'The :attribute must be an integer.',
            'numeric' => 'The :attribute must be a number.',
            'decimal' => 'The :attribute must be a number with :min to :max decimal places.',
            'boolean' => 'The :attribute field must be true or false.',
            'array' => 'The :attribute must be an array.',
            'list' => 'The :attribute must be a list.',
            'date' => 'The :attribute is not a valid date.',
            'json' => 'The :attribute must be a valid JSON string.',
            'email' => 'The :attribute must be a valid email address.',
            'alpha' => 'The :attribute must only contain letters.',
            'alpha_num' => 'The :attribute must only contain letters and numbers.',
            'regex' => 'The :attribute format is invalid.',
            'url' => 'The :attribute must be a valid URL.',
            'uuid' => 'The :attribute must be a valid UUID.',
            'ip' => 'The :attribute must be a valid IP address.',
            'in' => 'The selected :attribute is invalid.',
            'not_in' => 'The selected :attribute is invalid.',
            'between' => [
                'numeric' => 'The :attribute must be between :min and :max.',
                'string' => 'The :attribute must be between :min and :max characters.',
                'array' => 'The :attribute must have between :min and :max items.',
            ],
            'size' => [
                'numeric' => 'The :attribute must be :size.',
                'string' => 'The :attribute must be :size characters.',
                'array' => 'The :attribute must contain :size items.',
            ],
            'min' => [
                'numeric' => 'The :attribute must be at least :min.',
                'string' => 'The :attribute must be at least :min characters.',
                'array' => 'The :attribute must have at least :min items.',
            ],
            'max' => [
                'numeric' => 'The :attribute must not be greater than :max.',
                'string' => 'The :attribute must not be greater than :max characters.',
                'array' => 'The :attribute must not have more than :max items.',
            ],
            'confirmed' => 'The :attribute confirmation does not match.',
            'same' => 'The :attribute and :other must match.',
            'different' => 'The :attribute and :other must be different.',
            'file' => 'The :attribute must be a file.',
            'image' => 'The :attribute must be an image.',
            'mimes' => 'The :attribute must be a file of type: :values.',
            'mimetypes' => 'The :attribute must be a file of type: :values.',
            'max_file_size' => 'The :attribute must not be greater than :max kilobytes.',
            'min_file_size' => 'The :attribute must be at least :min kilobytes.',
            'dimensions' => 'The :attribute has invalid image dimensions.',
            'extensions' => 'The :attribute must have one of the following extensions: :values.',
            
            // Conditional required rules
            'required_if' => 'The :attribute field is required when :other is :values.',
            'required_if_accepted' => 'The :attribute field is required when :other is accepted.',
            'required_unless' => 'The :attribute field is required unless :other is in :values.',
            'required_with' => 'The :attribute field is required when :values is present.',
            'required_without' => 'The :attribute field is required when :values is not present.',
            'required_with_all' => 'The :attribute field is required when :values are present.',
            'required_without_all' => 'The :attribute field is required when none of :values are present.',
            
            // Comparison rules
            'gt' => [
                'numeric' => 'The :attribute must be greater than :value.',
                'string' => 'The :attribute must be greater than :value characters.',
                'array' => 'The :attribute must have more than :value items.',
            ],
            'gte' => [
                'numeric' => 'The :attribute must be greater than or equal to :value.',
                'string' => 'The :attribute must be greater than or equal to :value characters.',
                'array' => 'The :attribute must have :value items or more.',
            ],
            'lt' => [
                'numeric' => 'The :attribute must be less than :value.',
                'string' => 'The :attribute must be less than :value characters.',
                'array' => 'The :attribute must have less than :value items.',
            ],
            'lte' => [
                'numeric' => 'The :attribute must be less than or equal to :value.',
                'string' => 'The :attribute must be less than or equal to :value characters.',
                'array' => 'The :attribute must not have more than :value items.',
            ],
            
            // Date rules
            'after' => 'The :attribute must be a date after :date.',
            'after_or_equal' => 'The :attribute must be a date after or equal to :date.',
            'before' => 'The :attribute must be a date before :date.',
            'before_or_equal' => 'The :attribute must be a date before or equal to :date.',
            
            // String rules
            'starts_with' => 'The :attribute must start with one of the following: :values.',
            'ends_with' => 'The :attribute must end with one of the following: :values.',
            'digits' => 'The :attribute must be :digits digits.',
            'digits_between' => 'The :attribute must be between :min and :max digits.',
            
            // Acceptance rules
            'accepted' => 'The :attribute must be accepted.',
            'accepted_if' => 'The :attribute must be accepted when :other is :value.',
            'declined' => 'The :attribute must be declined.',
            'declined_if' => 'The :attribute must be declined when :other is :value.',
            
            // Other rules
            'filled' => 'The :attribute field must have a value.',
            'present' => 'The :attribute field must be present.',
            'prohibited' => 'The :attribute field is prohibited.',
            'prohibits' => 'The :attribute field prohibits :fields from being present.',
            'missing' => 'The :attribute field must be missing.',
            'missing_if' => 'The :attribute field must be missing when :other is :value.',
            'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
            'missing_with' => 'The :attribute field must be missing when :values is present.',
            'missing_with_all' => 'The :attribute field must be missing when :values are present.',
            'distinct' => 'The :attribute field has a duplicate value.',

            // New rules
            'active_url' => 'The :attribute is not a valid URL.',
            'alpha_dash' => 'The :attribute must only contain letters, numbers, dashes and underscores.',
            'ascii' => 'The :attribute must only contain ASCII characters.',
            'mac_address' => 'The :attribute must be a valid MAC address.',
            'ulid' => 'The :attribute must be a valid ULID.',
            'country' => 'The :attribute must be a valid ISO 3166-1 alpha-2 country code.',
            'language' => 'The :attribute must be a valid ISO 639-1 language code.',
            'enum' => 'The selected :attribute is invalid.',
            'exists' => 'The selected :attribute is invalid.',
            'unique' => 'The :attribute has already been taken.',
            'current_password' => 'The password is incorrect.',
            'password' => 'The :attribute must be at least :min characters.',
            'password_letters' => 'The :attribute must contain at least one letter.',
            'password_mixed' => 'The :attribute must contain at least one uppercase and one lowercase letter.',
            'password_numbers' => 'The :attribute must contain at least one number.',
            'password_symbols' => 'The :attribute must contain at least one symbol.',
            'uppercase' => 'The :attribute must be uppercase.',
            'lowercase' => 'The :attribute must be lowercase.',
            'date_format' => 'The :attribute does not match the format :format.',
            'date_equals' => 'The :attribute must be a date equal to :date.',
            'multiple_of' => 'The :attribute must be a multiple of :value.',
            'not_regex' => 'The :attribute format is invalid.',
            'doesnt_start_with' => 'The :attribute may not start with one of the following: :values.',
            'doesnt_end_with' => 'The :attribute may not end with one of the following: :values.',
            'timezone' => 'The :attribute must be a valid zone.',
            'required_array_keys' => 'The :attribute field must contain entries for: :values.',
            'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
            'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
        ];
    }
}
