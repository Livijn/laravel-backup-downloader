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
                 DownloadCommand::class,
             ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Register the main class to use with the facade
        $this->app->singleton('laravel-backup-downloader', function () {
            return new LaravelBackupDownloader;
        });
    }
}
