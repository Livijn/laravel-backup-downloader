<?php

namespace Livijn\LaravelBackupDownloader\Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mockery;
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
        $sqlStream = null;
        $mock = Mockery::mock($this->downloads);
        $mock->shouldReceive('writeStream')->twice()->andReturnUsing(
            function ($path, $stream) use (&$sqlStream) {
                if (str_ends_with($path, '.sql')) {
                    $sqlStream = $stream;

                    return false;
                }

                return $this->downloads->writeStream($path, $stream);
            },
        );
        Storage::set('backups-downloader', $mock);

        $this->artisan('backup:download')->assertExitCode(1);

        $this->assertSame('existing SQL', $this->downloads->get('dbdump.sql'));
        $this->assertSame(['dbdump.sql'], $this->downloads->allFiles());
        $this->assertFalse(is_resource($sqlStream));
    }

    public function test_an_incomplete_archive_write_preserves_the_existing_dump_and_closes_its_stream(): void
    {
        $this->downloads->put('dbdump.sql', 'existing SQL');
        $this->putBackup();
        $archiveStream = $this->backups->readStream($this->backup);
        $backupMock = Mockery::mock($this->backups);
        $backupMock->shouldReceive('readStream')->once()->andReturn($archiveStream);
        Storage::set('backups', $backupMock);
        $downloadMock = Mockery::mock($this->downloads);
        $downloadMock->shouldReceive('writeStream')->once()
            ->andReturnUsing(fn ($path, $stream) => $this->downloads->put($path, 'incomplete ZIP'));
        Storage::set('backups-downloader', $downloadMock);

        $this->artisan('backup:download')->assertExitCode(1);

        $this->assertSame('existing SQL', $this->downloads->get('dbdump.sql'));
        $this->assertSame(['dbdump.sql'], $this->downloads->allFiles());
        $this->assertFalse(is_resource($archiveStream));
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
