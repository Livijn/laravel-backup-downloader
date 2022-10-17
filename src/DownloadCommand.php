<?php
namespace Livijn\LaravelBackupDownloader;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use ZipArchive;
use Illuminate\Support\Facades\Storage;

class DownloadCommand extends Command
{
    protected $signature = 'backup:download {sql=mysql-forge.sql}';
    protected $description = 'Fetches a backup';
    protected Filesystem $storage;

    public function __construct()
    {
        parent::__construct();

        $this->storage = Storage::disk(config('backup.backup.destination.disks')[0]);
    }

    public function handle()
    {
        $sqlName = $this->argument('sql');

        if (config('app.env') == 'production') {
            $this->error('Nope, not in production.');
            return;
        }

        config()->set('filesystems.disks', array_merge(config('filesystems.disks'), [
            'backups-downloader' => [
                'driver' => 'local',
                'root'   => storage_path(),
            ],
        ]));

        $files = $this->getBackupFiles();

        $progressBar = $this->output->createProgressBar(100);
        $progressBar->start();

        $storage = Storage::disk('backups-downloader');
        $zipFile = 'data.zip';
        $sqlFile = 'backups/dbdump.sql';

        $storage->delete($sqlFile);

        $progressBar->advance(33);

        file_put_contents($storage->path($zipFile), $this->storage->get(Arr::last($files)));

        $progressBar->advance(33);

        $zip = new ZipArchive;
        $zip->open($storage->path($zipFile));
        $zip->extractTo($storage->path('/'));
        $zip->close();

        $progressBar->advance(33);

        $storage->delete($zipFile);

        $storage->move("db-dumps/$sqlName", $sqlFile);
        $storage->delete('db-dumps');

        $progressBar->finish();
    }

    private function getBackupFiles(): array
    {
        $backupName = (string)preg_replace('/[^a-zA-Z0-9.]/', '-', config('backup.backup.name'));
        $files = $this->storage->allFiles($backupName);

        if (count($files) === 0) {
            throw new \Exception('No files found.');
        }

        return $files;
    }
}
