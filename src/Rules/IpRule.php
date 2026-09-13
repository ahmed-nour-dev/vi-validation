<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::IP, aliases: ['ipv4', 'ipv6'])]
final class IpRule implements RuleInterface, NativeCompilableInterface
{
    private ?string $version;

    public function __construct(?string $version = null)
    {
        $this->version = $version;
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return ['rule' => 'ip'];
        }

        $flag = match ($this->version) {
            'v4', 'ipv4' => FILTER_FLAG_IPV4,
            'v6', 'ipv6' => FILTER_FLAG_IPV6,
            default => 0,
        };

        if (filter_var($value, FILTER_VALIDATE_IP, $flag) === false) {
            return ['rule' => 'ip'];
        }

        return null;
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        $v = $context->valName;
        $flag = match ($this->version) {
            'v4', 'ipv4' => 'FILTER_FLAG_IPV4',
            'v6', 'ipv6' => 'FILTER_FLAG_IPV6',
            default => '0',
        };
        $condition = "{$v} !== null && (!is_string({$v}) || filter_var({$v}, FILTER_VALIDATE_IP, {$flag}) === false)";

        return $context->emitError($condition, 'ip');
    }

    public function isImplicitForNative(): bool
    {
        return false;
    }
}
