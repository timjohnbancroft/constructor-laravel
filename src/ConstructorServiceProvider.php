<?php

namespace ConstructorIO\Laravel;

use ConstructorIO\Laravel\Search\ConstructorEngine;
use ConstructorIO\Laravel\Services\ConstructorAgentService;
use ConstructorIO\Laravel\Services\ConstructorService;
use ConstructorIO\Laravel\Services\Search\ConstructorSandboxSearch;
use Illuminate\Support\ServiceProvider;

class ConstructorServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../config/constructor.php',
            'constructor'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../config/constructor-catalog.php',
            'constructor-catalog'
        );

        // Register the main Constructor service (catalog management)
        $this->app->singleton(ConstructorService::class, function ($app) {
            return new ConstructorService(
                config('constructor.api_key'),
                config('constructor.api_token')
            );
        });

        // Register the search service
        $this->app->singleton(ConstructorSandboxSearch::class, function ($app) {
            return new ConstructorSandboxSearch(
                config('constructor.api_key'),
                config('constructor.api_token')
            );
        });

        // Register the agent service
        $this->app->singleton(ConstructorAgentService::class, function ($app) {
            return new ConstructorAgentService(
                config('constructor.api_key'),
                config('constructor.agent_domain')
            );
        });

        // Alias for facade
        $this->app->alias(ConstructorSandboxSearch::class, 'constructor');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish config files
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/constructor.php' => config_path('constructor.php'),
                __DIR__.'/../config/constructor-catalog.php' => config_path('constructor-catalog.php'),
            ], 'constructor-config');
        }

        // Register Scout engine if Scout is available
        $this->registerScoutEngine();
    }

    /**
     * Register the Constructor Scout engine.
     */
    protected function registerScoutEngine(): void
    {
        // Check if Scout is installed
        if (! class_exists(\Laravel\Scout\EngineManager::class)) {
            return;
        }

        $this->app->booted(function () {
            /** @var \Laravel\Scout\EngineManager $manager */
            $manager = $this->app->make(\Laravel\Scout\EngineManager::class);

            $manager->extend('constructor', function () {
                return new ConstructorEngine(
                    new ConstructorService(
                        config('scout.constructor.api_key', config('constructor.api_key')),
                        config('scout.constructor.api_token', config('constructor.api_token'))
                    ),
                    config('scout.soft_delete', false)
                );
            });
        });
    }
}
