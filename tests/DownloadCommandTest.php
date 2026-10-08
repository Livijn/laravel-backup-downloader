<?php

namespace Livijn\LaravelBackupDownloader\Tests;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livijn\LaravelBackupDownloader\DownloadCommand;
use Mockery;
use ReflectionMethod;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\BufferedOutput;
use ZipArchive;

class DownloadCommandTest extends TestCase
{
    private FilesystemAdapter $backups;

    private FilesystemAdapter $downloads;

    private string $backup = 'backups/2026-10-08.zip';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.env', 'local');
        config()->set('backup.backup.destination.disks', ['backups']);
        config()->set('backup.backup.name', 'backups');

        $this->backups = Storage::fake('backups');
        $this->downloads = Storage::fake('backups-downloader');
    }

    public function test_it_extracts_only_the_requested_sql_entry(): void
    {
        $this->putBackup([
            'db-dumps/mysql-forge.sql' => 'SELECT 1;',
            'db-dumps/another.sql' => 'SELECT 2;',
            'attachments/photo.jpg' => 'unrelated file',
        ]);

        $this->artisan('backup:download')->assertExitCode(0);

        $this->assertSame('SELECT 1;', $this->downloads->get('dbdump.sql'));
        $this->assertSame(['dbdump.cache.json', 'dbdump.sql'], $this->downloads->allFiles());
    }

    public function test_it_reuses_an_unchanged_backup_without_reading_its_archive(): void
    {
        $this->putBackup();
        $this->artisan('backup:download')->assertExitCode(0);
        $this->expectArchiveReads(0);

        $this->artisan('backup:download')
            ->expectsOutputToContain('Reusing dbdump.sql.')
            ->assertExitCode(0);
    }

    public function test_force_downloads_an_unchanged_backup_again(): void
    {
        $this->putBackup();
        $this->artisan('backup:download')->assertExitCode(0);
        $this->expectArchiveReads(1);

        $this->artisan('backup:download', ['--force' => true])->assertExitCode(0);
    }

    public function test_it_downloads_a_backup_whose_remote_size_changed(): void
    {
        $this->putBackup();
        $this->artisan('backup:download')->assertExitCode(0);
        $this->putBackup(['db-dumps/mysql-forge.sql' => 'SELECT a longer SQL statement;']);
        $this->expectArchiveReads(1);

        $this->artisan('backup:download')->assertExitCode(0);

        $this->assertSame('SELECT a longer SQL statement;', $this->downloads->get('dbdump.sql'));
    }

    public function test_it_downloads_a_backup_whose_remote_modification_time_changed(): void
    {
        $this->putBackup();
        $this->artisan('backup:download')->assertExitCode(0);
        touch($this->backups->path($this->backup), $this->backups->lastModified($this->backup) + 60);
        clearstatcache(true, $this->backups->path($this->backup));
        $this->expectArchiveReads(1);

        $this->artisan('backup:download')->assertExitCode(0);
    }

    public function test_it_downloads_a_newly_selected_backup(): void
    {
        $this->putBackup();
        $this->artisan('backup:download')->assertExitCode(0);
        $this->backup = 'backups/2026-10-09.zip';
        $this->putBackup();
        $this->expectArchiveReads(1);

        $this->artisan('backup:download')->assertExitCode(0);
    }

    public function test_it_downloads_again_when_the_source_disk_changes(): void
    {
        $this->putBackup();
        $this->artisan('backup:download')->assertExitCode(0);
        $otherDisk = Storage::fake('other-backups');
        $otherDisk->put($this->backup, $this->backups->get($this->backup));
        config()->set('backup.backup.destination.disks', ['other-backups']);
        $mock = Mockery::mock($otherDisk);
        $mock->shouldReceive('readStream')->once()->with($this->backup)
            ->andReturnUsing(fn ($path) => $otherDisk->readStream($path));
        Storage::set('other-backups', $mock);

        $this->artisan('backup:download')->assertExitCode(0);
    }

    public function test_it_downloads_again_when_a_different_sql_entry_is_requested(): void
    {
        $this->putBackup([
            'db-dumps/mysql-forge.sql' => 'SELECT 1;',
            'db-dumps/another.sql' => 'SELECT 2;',
        ]);
        $this->artisan('backup:download')->assertExitCode(0);
        $this->expectArchiveReads(1);

        $this->artisan('backup:download', ['sql' => 'another.sql'])->assertExitCode(0);

        $this->assertSame('SELECT 2;', $this->downloads->get('dbdump.sql'));
    }

    public function test_it_downloads_again_when_the_local_dump_size_changes(): void
    {
        $this->putBackup();
        $this->artisan('backup:download')->assertExitCode(0);
        $this->downloads->put('dbdump.sql', 'changed SQL');
        $this->expectArchiveReads(1);

        $this->artisan('backup:download')->assertExitCode(0);

        $this->assertSame('SELECT 1;', $this->downloads->get('dbdump.sql'));
    }

    public function test_it_downloads_again_when_the_local_dump_modification_time_changes(): void
    {
        $this->putBackup();
        $this->artisan('backup:download')->assertExitCode(0);
        touch($this->downloads->path('dbdump.sql'), $this->downloads->lastModified('dbdump.sql') + 60);
        $this->expectArchiveReads(1);

        $this->artisan('backup:download')->assertExitCode(0);
    }

    public function test_it_downloads_again_when_the_local_dump_is_missing(): void
    {
        $this->putBackup();
        $this->artisan('backup:download')->assertExitCode(0);
        $this->downloads->delete('dbdump.sql');
        $this->expectArchiveReads(1);

        $this->artisan('backup:download')->assertExitCode(0);

        $this->assertSame('SELECT 1;', $this->downloads->get('dbdump.sql'));
    }

    public function test_it_downloads_again_when_the_cache_is_invalid(): void
    {
        $this->putBackup();
        $this->downloads->put('dbdump.sql', 'existing SQL');
        $this->downloads->put('dbdump.cache.json', 'invalid JSON');
        $this->expectArchiveReads(1);

        $this->artisan('backup:download')->assertExitCode(0);

        $this->assertSame('SELECT 1;', $this->downloads->get('dbdump.sql'));
    }

    public function test_missing_sql_fails_and_preserves_the_existing_dump_and_cache(): void
    {
        $this->downloads->put('dbdump.sql', 'existing SQL');
        $this->downloads->put('dbdump.cache.json', '{"existing":true}');
        $this->putBackup(['attachments/photo.jpg' => 'no SQL']);

        $this->artisan('backup:download')->assertExitCode(1);

        $this->assertSame('existing SQL', $this->downloads->get('dbdump.sql'));
        $this->assertSame('{"existing":true}', $this->downloads->get('dbdump.cache.json'));
        $this->assertSame(['dbdump.cache.json', 'dbdump.sql'], $this->downloads->allFiles());
    }

    public function test_an_invalid_archive_fails_and_preserves_the_existing_dump(): void
    {
        $this->downloads->put('dbdump.sql', 'existing SQL');
        $this->backups->put($this->backup, 'invalid ZIP');

        $this->artisan('backup:download')->assertExitCode(1);

        $this->assertSame('existing SQL', $this->downloads->get('dbdump.sql'));
        $this->assertSame(['dbdump.sql'], $this->downloads->allFiles());
    }

    public function test_a_failed_archive_read_preserves_the_existing_dump(): void
    {
        $this->downloads->put('dbdump.sql', 'existing SQL');
        $this->putBackup();
        $mock = Mockery::mock($this->backups);
        $mock->shouldReceive('readStream')->once()->andReturn(false);
        Storage::set('backups', $mock);

        $this->artisan('backup:download')->assertExitCode(1);

        $this->assertSame('existing SQL', $this->downloads->get('dbdump.sql'));
        $this->assertSame(['dbdump.sql'], $this->downloads->allFiles());
    }

    public function test_a_failed_sql_write_preserves_the_existing_dump(): void
    {
        $this->downloads->put('dbdump.sql', 'existing SQL');
        $this->putBackup();
        $mock = Mockery::mock($this->downloads);
        $mock->shouldReceive('path')->andReturnUsing(fn ($path) => str_ends_with($path, '.sql')
            ? $this->downloads->path('missing-directory/dump.sql')
            : $this->downloads->path($path));
        Storage::set('backups-downloader', $mock);

        $this->artisan('backup:download')->assertExitCode(1);

        $this->assertSame('existing SQL', $this->downloads->get('dbdump.sql'));
        $this->assertSame(['dbdump.sql'], $this->downloads->allFiles());
    }

    public function test_an_incomplete_archive_read_preserves_the_existing_dump_and_closes_its_stream(): void
    {
        $this->downloads->put('dbdump.sql', 'existing SQL');
        $this->putBackup();
        $archiveStream = fopen('php://temp', 'w+b');
        fwrite($archiveStream, 'incomplete ZIP');
        rewind($archiveStream);
        $backupMock = Mockery::mock($this->backups);
        $backupMock->shouldReceive('readStream')->once()->andReturn($archiveStream);
        Storage::set('backups', $backupMock);

        $this->artisan('backup:download')->assertExitCode(1);

        $this->assertSame('existing SQL', $this->downloads->get('dbdump.sql'));
        $this->assertSame(['dbdump.sql'], $this->downloads->allFiles());
        $this->assertFalse(is_resource($archiveStream));
    }

    public function test_a_failed_archive_write_preserves_the_existing_dump_and_closes_its_stream(): void
    {
        $this->downloads->put('dbdump.sql', 'existing SQL');
        $this->putBackup();
        $archiveStream = $this->backups->readStream($this->backup);
        $backupMock = Mockery::mock($this->backups);
        $backupMock->shouldReceive('readStream')->once()->andReturn($archiveStream);
        Storage::set('backups', $backupMock);
        $downloadMock = Mockery::mock($this->downloads);
        $downloadMock->shouldReceive('path')->andReturn($this->downloads->path('missing-directory/archive.zip'));
        Storage::set('backups-downloader', $downloadMock);

        $this->artisan('backup:download')->assertExitCode(1);

        $this->assertSame('existing SQL', $this->downloads->get('dbdump.sql'));
        $this->assertSame(['dbdump.sql'], $this->downloads->allFiles());
        $this->assertFalse(is_resource($archiveStream));
    }

    public function test_it_shows_download_and_extraction_progress_with_real_byte_totals(): void
    {
        $this->putBackup();
        $output = new BufferedOutput;

        $this->assertSame(0, Artisan::call('backup:download', [], $output));
        $text = $output->fetch();

        $this->assertStringContainsString('Downloading archive (', $text);
        $this->assertStringContainsString('Extracting db-dumps/mysql-forge.sql (9 B)', $text);
        $this->assertStringContainsString('0 B / 9 B', $text);
        $this->assertStringContainsString('9 B / 9 B', $text);
        $this->assertStringContainsString('100%', $text);
        $this->assertStringContainsString('/s)', $text);
    }

    public function test_it_keeps_the_staging_archive_and_published_sql_private(): void
    {
        $this->putBackup();
        $mock = Mockery::mock($this->downloads);
        $mock->shouldReceive('size')->once()->andReturnUsing(function ($path) {
            $this->assertStringEndsWith('.zip', $path);
            $this->assertSame(0600, fileperms($this->downloads->path($path)) & 0777);

            return $this->downloads->size($path);
        });
        Storage::set('backups-downloader', $mock);
        $previousUmask = umask(0022);

        try {
            $this->artisan('backup:download')->assertExitCode(0);

            $this->assertSame(0600, fileperms($this->downloads->path('dbdump.sql')) & 0777);
        } finally {
            umask($previousUmask);
        }
    }

    public function test_s3_progress_runs_during_the_download_and_preserves_the_original_disk_configuration(): void
    {
        $this->putBackup();
        $size = $this->backups->size($this->backup);
        $previousCalls = [];
        $previousProgress = function (...$arguments) use (&$previousCalls): void {
            $previousCalls[] = $arguments;
        };
        $config = [
            'driver' => 's3',
            'bucket' => 'private-backups',
            'root' => 'prefix',
            'endpoint' => 'https://example.test',
            'stream_reads' => true,
            'options' => [
                'RequestPayer' => 'requester',
                '@http' => ['connect_timeout' => 15, 'progress' => $previousProgress, 'stream' => true],
            ],
        ];
        $original = Mockery::mock(AwsS3V3Adapter::class);
        $original->shouldReceive('allFiles')->once()->with('backups')->andReturn([$this->backup]);
        $original->shouldReceive('size')->once()->with($this->backup)->andReturn($size);
        $original->shouldReceive('lastModified')->once()->with($this->backup)->andReturn(123);
        $original->shouldReceive('getConfig')->once()->andReturn($config);
        $original->shouldNotReceive('readStream');
        Storage::set('backups', $original);
        $download = Mockery::mock(AwsS3V3Adapter::class);
        $builtConfig = null;
        $manager = Mockery::mock(Storage::getFacadeRoot());
        $manager->shouldReceive('build')->once()->andReturnUsing(function ($configuration) use (&$builtConfig, $download) {
            $builtConfig = $configuration;

            return $download;
        });
        Storage::swap($manager);
        $output = new BufferedOutput;
        $beforeCompletion = '';
        $download->shouldReceive('readStream')->once()->with($this->backup)->andReturnUsing(
            function () use (&$builtConfig, &$beforeCompletion, $output, $size) {
                $beforeCompletion = $output->fetch();
                // The SDK can report an unknown HTTP total; use the known S3 size.
                $callback = $builtConfig['options']['@http']['progress'];
                $bar = (new \ReflectionFunction($callback))->getStaticVariables()['progress'];
                $bar->setRedrawFrequency(1);
                $bar->minSecondsBetweenRedraws(0);
                $callback(0, (int) ($size / 2), 0, 0);
                $beforeCompletion .= $output->fetch();
                $callback(0, $size, 0, 0);

                return $this->backups->readStream($this->backup);
            },
        );

        $this->assertSame(0, Artisan::call('backup:download', [], $output));

        $this->assertStringContainsString('Downloading archive (', $beforeCompletion);
        $this->assertStringContainsString('0 B / '.$size.' B', $beforeCompletion);
        $this->assertStringContainsString((int) ($size / 2).' B / '.$size.' B', $beforeCompletion);
        $this->assertStringNotContainsString('Downloaded archive', $beforeCompletion);
        $this->assertCount(2, $previousCalls);
        $expectedConfig = $config;
        $expectedConfig['options']['@http']['stream'] = false;
        $expectedConfig['options']['@http']['progress'] = $builtConfig['options']['@http']['progress'];
        $this->assertSame($expectedConfig, $builtConfig);
        $this->assertSame($original, Storage::disk('backups'));
        $this->assertSame($config['options']['@http'], ['connect_timeout' => 15, 'progress' => $previousProgress, 'stream' => true]);
        $this->assertSame('SELECT 1;', $this->downloads->get('dbdump.sql'));
    }

    public function test_chunked_extraction_reports_intermediate_bytes_and_checks_the_crc_without_another_read(): void
    {
        $contents = str_repeat('x', 3 * 1024 * 1024);
        $source = fopen('php://temp', 'w+b');
        fwrite($source, $contents);
        rewind($source);
        $path = tempnam('/tmp', 'backup-progress-test-');
        $output = new BufferedOutput;
        $progress = new ProgressBar($output, strlen($contents));
        $progress->setFormat('%percent%% %message%');
        $progress->setRedrawFrequency(1);
        $progress->minSecondsBetweenRedraws(0);
        $progress->start();

        try {
            (new ReflectionMethod(DownloadCommand::class, 'copyStreamToFile'))->invoke(
                new DownloadCommand,
                $source,
                $path,
                $progress,
                strlen($contents),
                microtime(true),
                crc32($contents),
            );

            $text = $output->fetch();
            $this->assertStringContainsString('33% 1.0 MiB / 3.0 MiB', $text);
            $this->assertStringContainsString('66% 2.0 MiB / 3.0 MiB', $text);
            $this->assertStringContainsString('100% 3.0 MiB / 3.0 MiB', $text);
            $this->assertSame($contents, file_get_contents($path));
        } finally {
            fclose($source);
            unlink($path);
        }
    }

    public function test_a_failed_cache_write_preserves_the_existing_dump(): void
    {
        $this->downloads->put('dbdump.sql', 'existing SQL');
        $this->putBackup();
        $mock = Mockery::mock($this->downloads);
        $mock->shouldReceive('put')->once()->andReturn(false);
        Storage::set('backups-downloader', $mock);

        $this->artisan('backup:download')->assertExitCode(1);

        $this->assertSame('existing SQL', $this->downloads->get('dbdump.sql'));
        $this->assertSame(['dbdump.sql'], $this->downloads->allFiles());
    }

    public function test_a_failed_sql_move_preserves_the_existing_dump_and_invalidates_the_cache(): void
    {
        $this->downloads->put('dbdump.sql', 'existing SQL');
        $this->downloads->put('dbdump.cache.json', '{"existing":true}');
        $this->putBackup();
        $mock = Mockery::mock($this->downloads);
        $mock->shouldReceive('move')->once()->andReturn(false);
        Storage::set('backups-downloader', $mock);

        $this->artisan('backup:download')->assertExitCode(1);

        $this->assertSame('existing SQL', $this->downloads->get('dbdump.sql'));
        $this->assertSame(['dbdump.sql'], $this->downloads->allFiles());
    }

    public function test_a_failed_cache_move_still_succeeds_with_the_new_valid_dump(): void
    {
        $this->downloads->put('dbdump.sql', 'existing SQL');
        $this->downloads->put('dbdump.cache.json', '{"existing":true}');
        $this->putBackup();
        $mock = Mockery::mock($this->downloads);
        $mock->shouldReceive('move')->twice()->andReturnUsing(
            fn ($from, $to) => $to === 'dbdump.cache.json'
                ? false
                : $this->downloads->move($from, $to),
        );
        Storage::set('backups-downloader', $mock);

        $this->artisan('backup:download')
            ->expectsOutputToContain('its cache could not be saved')
            ->assertExitCode(0);

        $this->assertSame('SELECT 1;', $this->downloads->get('dbdump.sql'));
        $this->assertSame(['dbdump.sql'], $this->downloads->allFiles());
    }

    public function test_it_fails_when_no_backups_exist(): void
    {
        $this->artisan('backup:download')->assertExitCode(1);
    }

    public function test_it_fails_when_no_backup_matches_the_requested_name(): void
    {
        $this->putBackup();

        $this->artisan('backup:download', ['name' => 'missing'])->assertExitCode(1);
    }

    public function test_it_fails_in_production(): void
    {
        config()->set('app.env', 'production');

        $this->artisan('backup:download')->assertExitCode(1);
    }

    private function expectArchiveReads(int $count): void
    {
        $mock = Mockery::mock($this->backups);
        $mock->shouldReceive('readStream')->times($count)->with($this->backup)
            ->andReturnUsing(fn ($path) => $this->backups->readStream($path));
        Storage::set('backups', $mock);
    }

    private function putBackup(array $entries = ['db-dumps/mysql-forge.sql' => 'SELECT 1;']): void
    {
        $path = tempnam('/tmp', 'backup-downloader-test-');

        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

            foreach ($entries as $entry => $contents) {
                $zip->addFromString($entry, $contents);
            }

            $zip->close();
            $this->backups->put($this->backup, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
