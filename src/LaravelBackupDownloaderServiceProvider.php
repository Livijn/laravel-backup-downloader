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
                 ImportCommand::class,
             ]);
        }
    }
}
