<?php

namespace Livijn\LaravelBackupDownloader;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class DownloadCommand extends Command
{
    protected $signature = 'backup:download {name?} {sql=mysql-forge.sql}';

    protected $description = 'Fetches a backup';

    protected ?Filesystem $backupStorage;

    public function handle()
    {
        $this->backupStorage = Storage::disk(config('backup.backup.destination.disks')[0]);

        if (config('app.env') == 'production') {
            $this->error('Nope, not in production.');

            return;
        }

        config()->set('filesystems.disks', array_merge(config('filesystems.disks'), [
            'backups-downloader' => [
                'driver' => 'local',
                'root' => storage_path(),
            ],
        ]));

        $files = $this->getBackupFiles();

        $backupFile = $this->argument('name')
            ? Arr::first($files, fn ($name) => Str::contains($name, $this->argument('name')))
            : Arr::last($files);

        $this->output->title('Downloading backup: '.$backupFile);

        $progressBar = $this->output->createProgressBar(100);
        $progressBar->start();

        $storage = Storage::disk('backups-downloader');
        $zipFile = 'data.zip';
        $sqlFile = 'backups/dbdump.sql';

        $storage->delete($sqlFile);

        $progressBar->advance(33);

        $storage->writeStream($zipFile, $this->backupStorage->readStream($backupFile));

        $progressBar->advance(33);

        $zip = new ZipArchive;
        $zip->open($storage->path($zipFile));
        $zip->extractTo($storage->path('/'));
        $zip->close();

        $progressBar->advance(33);

        $storage->delete($zipFile);

        $storage->move('db-dumps/'.$this->argument('sql'), $sqlFile);
        $storage->deleteDirectory('db-dumps');

        $progressBar->finish();
    }

    private function getBackupFiles(): array
    {
        $files = $this->backupStorage->allFiles(config('backup.backup.name'));

        if (count($files) === 0) {
            throw new \Exception('No files found.');
        }

        return $files;
    }
}
