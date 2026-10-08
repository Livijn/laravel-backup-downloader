<?php

namespace Livijn\LaravelBackupDownloader;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class DownloadCommand extends Command
{
    protected $signature = 'backup:download {name?} {sql=mysql-forge.sql} {--force : Download even when the selected backup is already available locally}';

    protected $description = 'Fetches a backup';

    protected ?Filesystem $backupStorage;

    public function handle(): int
    {
        if (config('app.env') == 'production') {
            $this->error('Nope, not in production.');

            return self::FAILURE;
        }

        $storage = null;
        $archiveStream = null;
        $sqlStream = null;
        $zip = null;
        $temporaryFiles = [];

        try {
            $disk = config('backup.backup.destination.disks.0');

            if (! is_string($disk) || $disk === '') {
                throw new RuntimeException('No backup destination disk is configured.');
            }

            $this->backupStorage = Storage::disk($disk);

            config()->set('filesystems.disks.backups-downloader', [
                'driver' => 'local',
                'root' => storage_path('app/private'),
            ]);

            $storage = Storage::disk('backups-downloader');
            $startedAt = microtime(true);
            $files = $this->getBackupFiles();
            $backupFile = $this->argument('name')
                ? Arr::first($files, fn ($name) => Str::contains($name, $this->argument('name')))
                : Arr::last($files);

            if ($backupFile === null) {
                throw new RuntimeException('No backup matches the requested name.');
            }

            $entry = 'db-dumps/'.$this->argument('sql');
            $source = [
                'disk' => $disk,
                'path' => $backupFile,
                'size' => $this->backupStorage->size($backupFile),
                'last_modified' => $this->backupStorage->lastModified($backupFile),
                'entry' => $entry,
            ];

            $this->line(sprintf('Selected backup in %.1fs: %s', microtime(true) - $startedAt, $backupFile));

            if (! $this->option('force') && $this->hasCachedDump($storage, $source)) {
                $this->info('The selected backup is already downloaded. Reusing dbdump.sql.');

                return self::SUCCESS;
            }

            $prefix = '.backup-download-'.bin2hex(random_bytes(8));
            $zipFile = $prefix.'.zip';
            $sqlFile = $prefix.'.sql';
            $cacheFile = $prefix.'.json';
            $temporaryFiles = [$zipFile, $sqlFile, $cacheFile];

            $startedAt = microtime(true);
            $archiveStream = $this->backupStorage->readStream($backupFile);

            if (! is_resource($archiveStream) || ! $storage->writeStream($zipFile, $archiveStream)) {
                throw new RuntimeException('Unable to download the backup archive.');
            }

            fclose($archiveStream);
            $archiveStream = null;

            if ($storage->size($zipFile) !== $source['size']) {
                throw new RuntimeException('The backup archive was not completely downloaded.');
            }

            $this->line(sprintf('Downloaded archive in %.1fs.', microtime(true) - $startedAt));

            $startedAt = microtime(true);
            $zip = new ZipArchive;

            if ($zip->open($storage->path($zipFile), ZipArchive::CHECKCONS) !== true) {
                $zip = null;

                throw new RuntimeException('Unable to open the backup archive.');
            }

            $entryStat = $zip->statName($entry);
            $sqlStream = $zip->getStream($entry);

            if ($entryStat === false || ! is_resource($sqlStream)) {
                throw new RuntimeException('The backup archive does not contain '.$entry.'.');
            }

            if (! $storage->writeStream($sqlFile, $sqlStream)) {
                throw new RuntimeException('Unable to write the SQL dump.');
            }

            fclose($sqlStream);
            $sqlStream = null;
            $zip->close();
            $zip = null;
            clearstatcache(true, $storage->path($sqlFile));
            $sqlSize = filesize($storage->path($sqlFile));
            $sqlModified = filemtime($storage->path($sqlFile));

            if ($sqlSize !== $entryStat['size'] || $sqlModified === false) {
                throw new RuntimeException('The SQL dump was not completely extracted.');
            }

            $cache = json_encode([
                'source' => $source,
                'sql_size' => $sqlSize,
                'sql_last_modified' => $sqlModified,
            ], JSON_THROW_ON_ERROR);

            if (! $storage->put($cacheFile, $cache)) {
                throw new RuntimeException('Unable to write the download cache.');
            }

            $this->publishDump($storage, $sqlFile, $cacheFile);
            $this->line(sprintf('Extracted %s in %.1fs.', $entry, microtime(true) - $startedAt));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Backup download failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($archiveStream)) {
                fclose($archiveStream);
            }

            if (is_resource($sqlStream)) {
                fclose($sqlStream);
            }

            if ($zip !== null) {
                $zip->close();
            }

            if ($storage !== null && $temporaryFiles !== []) {
                try {
                    if (! $storage->delete($temporaryFiles)) {
                        $this->warn('Unable to remove temporary download files.');
                    }
                } catch (Throwable $exception) {
                    $this->warn('Unable to remove temporary download files: '.$exception->getMessage());
                }
            }
        }
    }

    private function hasCachedDump(Filesystem $storage, array $source): bool
    {
        if (! $storage->exists('dbdump.sql') || ! $storage->exists('dbdump.cache.json')) {
            return false;
        }

        $metadata = $storage->get('dbdump.cache.json');

        if (! is_string($metadata)) {
            return false;
        }

        $cache = json_decode($metadata, true);
        clearstatcache(true, $storage->path('dbdump.sql'));

        return is_array($cache)
            && ($cache['source'] ?? null) === $source
            && ($cache['sql_size'] ?? null) === filesize($storage->path('dbdump.sql'))
            && ($cache['sql_last_modified'] ?? null) === filemtime($storage->path('dbdump.sql'));
    }

    private function publishDump(Filesystem $storage, string $sqlFile, string $cacheFile): void
    {
        if (! $storage->delete('dbdump.cache.json')) {
            throw new RuntimeException('Unable to invalidate the existing download cache.');
        }

        if (! $storage->move($sqlFile, 'dbdump.sql')) {
            throw new RuntimeException('Unable to replace the SQL dump.');
        }

        try {
            if (! $storage->move($cacheFile, 'dbdump.cache.json')) {
                throw new RuntimeException('Unable to replace the download cache.');
            }
        } catch (Throwable $exception) {
            $storage->delete('dbdump.cache.json');
            $this->warn('SQL dump downloaded, but its cache could not be saved: '.$exception->getMessage());
        }
    }

    private function getBackupFiles(): array
    {
        $files = $this->backupStorage->allFiles(config('backup.backup.name'));

        if (count($files) === 0) {
            throw new RuntimeException('No backup files found.');
        }

        sort($files);

        return $files;
    }
}
