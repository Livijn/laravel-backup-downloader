<?php

namespace Livijn\LaravelBackupDownloader;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Helper\ProgressBar;
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
        $progress = null;
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
            $this->line('Downloading archive ('.$this->formatBytes($source['size']).')...');
            $progress = $this->startProgress($source['size']);
            $downloadStorage = $this->downloadStorage($progress, $source['size'], $startedAt);
            $archiveStream = $downloadStorage->readStream($backupFile);

            if (! is_resource($archiveStream)) {
                throw new RuntimeException('Unable to download the backup archive.');
            }

            $this->copyStreamToFile(
                $archiveStream,
                $storage->path($zipFile),
                $downloadStorage instanceof AwsS3V3Adapter ? null : $progress,
                $source['size'],
                $startedAt,
            );
            fclose($archiveStream);
            $archiveStream = null;

            if ($storage->size($zipFile) !== $source['size']) {
                throw new RuntimeException('The backup archive was not completely downloaded.');
            }

            $progress->finish();
            $this->newLine();
            $progress = null;
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

            $this->line('Extracting '.$entry.' ('.$this->formatBytes($entryStat['size']).')...');
            $progress = $this->startProgress($entryStat['size']);
            $this->copyStreamToFile(
                $sqlStream,
                $storage->path($sqlFile),
                $progress,
                $entryStat['size'],
                $startedAt,
                $entryStat['crc'],
            );

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

            $progress->finish();
            $this->newLine();
            $progress = null;
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
            if ($progress !== null) {
                $this->newLine();
            }

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

    private function downloadStorage(ProgressBar $progress, int $size, float $startedAt): Filesystem
    {
        if (! $this->backupStorage instanceof AwsS3V3Adapter) {
            return $this->backupStorage;
        }

        // S3 readStream() buffers the HTTP response before returning its stream.
        // Attach progress to a private disk so it reports the actual download and
        // does not change the configured disk used elsewhere in the application.
        $config = $this->backupStorage->getConfig();
        $previousProgress = $config['options']['@http']['progress'] ?? null;
        $config['options']['@http']['stream'] = false;
        $config['options']['@http']['progress'] = function ($downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($previousProgress, $progress, $size, $startedAt): void {
            if (is_callable($previousProgress)) {
                $previousProgress($downloadTotal, $downloaded, $uploadTotal, $uploaded);
            }

            $this->updateProgress($progress, (int) $downloaded, $size, $startedAt);
        };

        return Storage::build($config);
    }

    private function startProgress(int $size): ProgressBar
    {
        $progress = $this->output->createProgressBar(max(1, $size));
        $progress->setFormat(' %percent:3s%% [%bar%] %message% ETA %remaining:6s%');
        $progress->setMessage('0 B / '.$this->formatBytes($size));
        $progress->setRedrawFrequency(1024 * 1024);
        $progress->minSecondsBetweenRedraws(0.2);
        $progress->maxSecondsBetweenRedraws(1);
        $progress->start();

        return $progress;
    }

    private function updateProgress(ProgressBar $progress, int $bytes, int $size, float $startedAt): void
    {
        $speed = $bytes / max(0.001, microtime(true) - $startedAt);
        $progress->setMessage($this->formatBytes($bytes).' / '.$this->formatBytes($size).' ('.$this->formatBytes((int) $speed).'/s)');
        $progress->setProgress(min($bytes, $size));
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $unit = min(4, (int) floor(log(max(1, $bytes), 1024)));

        return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $bytes / (1024 ** $unit), $units[$unit]);
    }

    private function copyStreamToFile($source, string $path, ?ProgressBar $progress, int $size, float $startedAt, ?int $expectedCrc = null): void
    {
        $destination = @fopen($path, 'wb');

        if (! is_resource($destination)) {
            throw new RuntimeException('Unable to write '.$path.'.');
        }

        $bytes = 0;
        $checksum = $expectedCrc === null ? null : hash_init('crc32b');

        try {
            if (! @chmod($path, 0600)) {
                throw new RuntimeException('Unable to set private permissions on '.$path.'.');
            }

            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    throw new RuntimeException('Unable to read the backup stream.');
                }

                $length = strlen($chunk);
                $offset = 0;

                while ($offset < $length) {
                    $written = fwrite($destination, substr($chunk, $offset));

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Unable to write '.$path.'.');
                    }

                    $offset += $written;
                }

                if ($checksum !== null) {
                    hash_update($checksum, $chunk);
                }

                $bytes += $length;

                if ($progress !== null) {
                    $this->updateProgress($progress, $bytes, $size, $startedAt);
                }
            }

            if (! fflush($destination)) {
                throw new RuntimeException('Unable to flush '.$path.'.');
            }

            if ($checksum !== null && hash_final($checksum) !== sprintf('%08x', $expectedCrc)) {
                throw new RuntimeException('The SQL dump checksum does not match the archive.');
            }
        } finally {
            fclose($destination);
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
