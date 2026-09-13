<?php

declare(strict_types=1);

namespace Vi\Validation\Execution;

use Vi\Validation\Messages\MessageResolver;

final class ValidationResult
{
    /** @var array<string, list<array{rule: string, params: array<string, mixed>, message: string|null}>> */
    private array $errors;
    private ?MessageResolver $messageResolver;
    /** @var array<string, mixed> */
    private array $data;
    /** @var list<string> */
    private array $excludedFields;

    /**
     * @param ErrorCollector|array<string, list<array{rule: string, params: array<string, mixed>, message: string|null}>> $errors
     * @param array<string, mixed> $data
     * @param list<string> $excludedFields
     */
    public function __construct($errors, array $data = [], ?MessageResolver $messageResolver = null, array $excludedFields = [])
    {
        if ($errors instanceof ErrorCollector) {
            $this->errors = $errors->all();
        } else {
            $this->errors = $errors;
        }
        $this->data = $data;
        $this->messageResolver = $messageResolver;
        $this->excludedFields = $excludedFields;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * Return {@see data()} with every excluded field removed.
     *
     * An excluded field name may address a nested path ('profile.address.city') or contain
     * a '*' wildcard segment ('items.*.sku'), matching every element of the array at that
     * position. Removal walks the path segment by segment and rebuilds only the branches it
     * touches, so {@see data()} is never mutated and sibling data is left untouched. A path
     * (or wildcard branch) that doesn't exist in the data is silently ignored - this also
     * makes it safe to exclude both a parent and one of its children, in either order.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->data;

        foreach ($this->excludedFields as $field) {
            /** @var array<string, mixed> $validated */
            $validated = $this->removeExcludedPath($validated, explode('.', $field));
        }

        return $validated;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $segments
     * @return array<array-key, mixed>
     */
    private function removeExcludedPath(array $data, array $segments): array
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return $data;
        }

        if ($segment === '*') {
            foreach ($data as $key => $value) {
                if ($segments === []) {
                    unset($data[$key]);
                } elseif (is_array($value)) {
                    $data[$key] = $this->removeExcludedPath($value, $segments);
                }
            }

            return $data;
        }

        if (!array_key_exists($segment, $data)) {
            return $data;
        }

        if ($segments === []) {
            unset($data[$segment]);
            return $data;
        }

        if (is_array($data[$segment])) {
            $data[$segment] = $this->removeExcludedPath($data[$segment], $segments);
        }

        return $data;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, list<array{rule: string, params: array<string, mixed>, message: string|null}>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get formatted error messages grouped by field.
     *
     * @return array<string, list<string>>
     */
    public function messages(): array
    {
        $messages = [];
        $rawErrors = $this->errors;

        foreach ($rawErrors as $field => $fieldErrors) {
            $messages[$field] = [];
            foreach ($fieldErrors as $error) {
                $message = $error['message'];
                
                if ($message === null && $this->messageResolver !== null) {
                    $message = $this->messageResolver->resolve($field, $error['rule'], $error['params'] ?? []);
                }

                $messages[$field][] = $message ?? $error['rule'];
            }
        }

        return $messages;
    }

    /**
     * Get all error messages as a flat array.
     *
     * @return list<string>
     */
    public function allMessages(): array
    {
        $all = [];
        foreach ($this->messages() as $messages) {
            array_push($all, ...$messages);
        }
        return $all;
    }

    /**
     * Get the first error message for a given field.
     */
    public function firstMessage(string $field): ?string
    {
        $messages = $this->messages();
        return $messages[$field][0] ?? null;
    }

    /**
     * Get the first error message from all fields.
     */
    public function first(): ?string
    {
        $all = $this->allMessages();
        return $all[0] ?? null;
    }

    /**
     * Convert the validation result to a string.
     */
    public function __toString(): string
    {
        if ($this->isValid()) {
            return 'Validation passed.';
        }

        return implode("\n", $this->allMessages());
    }
}
