<?php
namespace Livijn\LaravelBackupDownloader;

use Illuminate\Support\ServiceProvider;

class LaravelBackupDownloaderServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
             $this->commands([
                 new DownloadCommand,
             ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'laravel-backup-downloader');

        // Register the main class to use with the facade
        $this->app->singleton('laravel-backup-downloader', function () {
            return new LaravelBackupDownloader;
        });
    }
}
