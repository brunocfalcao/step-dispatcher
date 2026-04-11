<?php

declare(strict_types=1);

namespace StepDispatcher;

use Illuminate\Support\ServiceProvider;
use StepDispatcher\Commands\DispatchStepsCommand;
use StepDispatcher\Commands\PurgeStepsCommand;
use StepDispatcher\Commands\RecoverStaleStepsCommand;
use StepDispatcher\Models\Step;
use StepDispatcher\Observers\StepObserver;

final class StepDispatcherServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/step-dispatcher.php',
            'step-dispatcher'
        );
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
                DispatchStepsCommand::class,
                PurgeStepsCommand::class,
                RecoverStaleStepsCommand::class,
            ]);
        }

        Step::observe(StepObserver::class);
    }
}
