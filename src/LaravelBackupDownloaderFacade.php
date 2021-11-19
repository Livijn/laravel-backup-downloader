<?php
namespace Livijn\LaravelBackupDownloader;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Livijn\LaravelBackupDownloader\Skeleton\SkeletonClass
 */
class LaravelBackupDownloaderFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'laravel-backup-downloader';
    }
}
