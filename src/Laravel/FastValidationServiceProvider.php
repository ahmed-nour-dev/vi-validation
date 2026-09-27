<?php

declare(strict_types=1);

namespace Vi\Validation\Laravel;

use Illuminate\Support\Facades\Validator as LaravelValidator;
use Illuminate\Support\ServiceProvider;

use Vi\Validation\Rules\RuleRegistry;

final class FastValidationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuleRegistry::class, function () {
            $registry = new RuleRegistry();
            $registry->registerBuiltInRules();
            return $registry;
        });

        $this->app->singleton(FastValidatorFactory::class, function ($app) {
            $config = (array) config('fast-validation', []);
            // Sign persisted cache files/native artifacts with the app key unless a dedicated
            // key is configured, so files planted in the cache directories are never trusted.
            $config['security']['signing_key'] = ($config['security']['signing_key'] ?? null)
                ?: (config('app.key') ?: null);

            $factory = new FastValidatorFactory($config, $app->make(RuleRegistry::class));

            // exists/unique use Laravel's presence verifier and current_password uses its auth +
            // hasher, resolved lazily on first use - without them those rules fail closed.
            if ($app->bound('validation.presence') || $app->bound('db')) {
                $factory->setDatabaseValidator(new PresenceVerifierDatabaseValidator(
                    static fn () => $app->make('validation.presence')
                ));
            }
            if ($app->bound('auth') && $app->bound('hash')) {
                $factory->setPasswordHasher(new AuthPasswordHasher(
                    static fn () => $app->make('auth'),
                    static fn () => $app->make('hash')
                ));
            }

            return $factory;
        });

        $this->app->alias(FastValidatorFactory::class, 'fast.validator');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/fast-validation.php' => config_path('fast-validation.php'),
        ], 'config');

        $mode = config('fast-validation.mode', 'parallel');

        if ($mode === 'override') {
            $this->overrideLaravelValidator();
        }
    }

    private function overrideLaravelValidator(): void
    {
        $this->app->extend('validator', function ($validator, $app) {
            $factory = $app->make(FastValidatorFactory::class);

            return new LaravelValidatorAdapter($factory, $validator);
        });
    }
}
