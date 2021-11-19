<?php
namespace Livijn\LaravelBackupDownloader;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use ZipArchive;
use Illuminate\Support\Facades\Storage;

class DownloadCommand extends Command
{
    protected $signature = 'backup:download';
    protected $description = 'Fetches a backup';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        if (config('app.env') == 'production') {
            $this->error('Nope, not in production.');
            return;
        }

        $progressBar = $this->output->createProgressBar(100);
        $progressBar->start();

        $files = Storage::allFiles('backups/Skaffa-Hund');
        $storage = Storage::disk('database');
        $zipFile = 'data.zip';
        $sqlFile = 'dbdump.sql';

        $storage->delete($sqlFile);

        $progressBar->advance(33);

        file_put_contents($storage->path($zipFile), Storage::get(Arr::last($files)));

        $progressBar->advance(33);

        $zip = new ZipArchive;
        $zip->open($storage->path($zipFile));
        $zip->extractTo($storage->path('/'));
        $zip->close();

        $progressBar->advance(33);

        $storage->delete($zipFile);

        $storage->move('db-dumps/mysql-forge.sql', $sqlFile);
        $storage->deleteDir('db-dumps');

        $progressBar->finish();
    }
}
