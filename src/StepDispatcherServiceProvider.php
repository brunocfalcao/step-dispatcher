<?php

declare(strict_types=1);

namespace StepDispatcher;

use Illuminate\Support\ServiceProvider;
use StepDispatcher\Commands\ArchiveStepsCommand;
use StepDispatcher\Commands\DispatchStepsCommand;
use StepDispatcher\Commands\InstallPrefixedTablesCommand;
use StepDispatcher\Commands\PurgeStepsCommand;
use StepDispatcher\Commands\RecoverStaleStepsCommand;
use StepDispatcher\Models\Step;
use StepDispatcher\Observers\StepObserver;
use StepDispatcher\Support\RuntimeContext;

final class StepDispatcherServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/step-dispatcher.php',
            'step-dispatcher'
        );

        // Scoped binding — one RuntimeContext per request / per
        // queued job under Octane, behaves as a singleton under
        // FPM. The push/pop pairings on the prefix stack must be
        // observed across multiple resolutions, so a default
        // (transient) binding would defeat the mechanism.
        $this->app->scoped(RuntimeContext::class, static fn () => new RuntimeContext);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/step-dispatcher.php' => config_path('step-dispatcher.php'),
        ], 'step-dispatcher-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'step-dispatcher-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ArchiveStepsCommand::class,
                DispatchStepsCommand::class,
                InstallPrefixedTablesCommand::class,
                PurgeStepsCommand::class,
                RecoverStaleStepsCommand::class,
            ]);
        }

        Step::observe(StepObserver::class);
    }
}
