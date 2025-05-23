<?php

namespace Lac\Providers;

use Lac\Services\LacService;
use Illuminate\Support\ServiceProvider;
use Lac\Console\Commands\DbImportCommand;
use Lac\Console\Commands\ScaffoldCommand;
use Lac\Console\Commands\DbTemplateCommand;
use Lac\Console\Commands\SyncRoutesCommand;
use Lac\Console\Commands\SyncModelRelCommand;
use Lac\Console\Commands\SyncValidationsCommand;
use Lac\Console\Commands\GenerateMigrationCommand;

class LacServiceProvider extends ServiceProvider {
    /**
     * Register package services
     */
    public function register(): void {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__.'/../../config/lac.php', 'lac'
        );
        
        // Service binding
        $this->app->singleton('lac', function ($app) {
            return new LacService();
        });
    }

    /**
     * Bootstrap package services
     */
    public function boot(): void {
        // Publish configuration
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/lac.php' => config_path('lac.php'),
            ], 'lac-config');
            
            // Publish stub files
            $this->publishes([
                __DIR__.'/../../stubs' => base_path('stubs/lac'),
            ], 'lac-stubs');
            
            // Register Artisan commands
            $this->commands([
                ScaffoldCommand::class,
                SyncRoutesCommand::class,
                SyncValidationsCommand::class,
                SyncModelRelCommand::class,
                DbTemplateCommand::class,
                DbImportCommand::class,
                GenerateMigrationCommand::class
            ]);
        }
    }
}