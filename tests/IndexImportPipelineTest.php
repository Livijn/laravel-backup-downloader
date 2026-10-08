<?php

namespace Livijn\LaravelBackupDownloader\Tests;

use Illuminate\Console\OutputStyle;
use Livijn\LaravelBackupDownloader\ImportCommand;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class IndexImportPipelineTest extends TestCase
{
    private string $directory;

    private string|false $previousPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/backup-index-import-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);

        $this->writeMysqlStub();
        file_put_contents($this->directory.'/pv', "#!/bin/sh\ncase \"\$1\" in\n--size=*) exec /bin/cat ;;\n*) exec /bin/cat \"\$1\" ;;\nesac\n");
        chmod($this->directory.'/pv', 0755);

        $this->previousPath = getenv('PATH');
        putenv('PATH='.$this->directory.':'.($this->previousPath ?: '/usr/bin:/bin'));
    }

    protected function tearDown(): void
    {
        $this->previousPath === false ? putenv('PATH') : putenv('PATH='.$this->previousPath);

        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_it_loads_retained_rows_before_building_secondary_indexes(): void
    {
        $sql = $this->dump();
        file_put_contents($this->directory.'/dump.sql', $sql);

        $this->assertTrue($this->importFile(true, ['views']));

        $imported = file_get_contents($this->directory.'/imported.sql');
        $this->assertStringContainsString("PRIMARY KEY (`id`)\n) ENGINE=InnoDB;", $imported);
        $this->assertStringContainsString("INSERT INTO `notifications` VALUES (1, '2026-10-08');", $imported);
        $this->assertStringNotContainsString('INSERT INTO `views`', $imported);
        $this->assertStringContainsString('ALTER TABLE `notifications` ADD KEY `notifications_created_at` (`created_at`);', $imported);
        $this->assertGreaterThan(strpos($imported, 'INSERT INTO `notifications`'), strpos($imported, 'ALTER TABLE `notifications`'));
        $this->assertStringNotContainsString("--force\n", file_get_contents($this->directory.'/arguments'));
    }

    public function test_the_original_mode_keeps_indexes_and_existing_mysql_options(): void
    {
        file_put_contents($this->directory.'/dump.sql', $this->dump());

        $this->assertTrue($this->importFile(false));
        $this->assertSame($this->dump(), file_get_contents($this->directory.'/imported.sql'));
        $this->assertStringContainsString("--force\n", file_get_contents($this->directory.'/arguments'));
    }

    public function test_it_reports_a_failed_sql_consumer_in_index_deferred_mode(): void
    {
        file_put_contents($this->directory.'/dump.sql', $this->dump());
        $this->writeMysqlStub(1);

        $this->assertFalse($this->importFile(true));
    }

    private function writeMysqlStub(int $exitCode = 0): void
    {
        file_put_contents($this->directory.'/mysql', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($this->directory.'/arguments')."\n/bin/cat > ".escapeshellarg($this->directory.'/imported.sql')."\nexit ".$exitCode."\n");
        chmod($this->directory.'/mysql', 0755);
    }

    private function importFile(bool $deferIndexes, array $tablesToSkip = []): bool
    {
        $command = new ImportCommand;
        $input = new ArrayInput(['--defer-indexes' => $deferIndexes], $command->getDefinition());
        $command->setInput($input);
        $command->setOutput(new OutputStyle($input, new BufferedOutput));

        return (new ReflectionMethod(ImportCommand::class, 'importFile'))->invoke($command, 'testing', $this->directory.'/dump.sql', $tablesToSkip);
    }

    private function dump(): string
    {
        return <<<'SQL'
CREATE TABLE `notifications` (
  `id` bigint NOT NULL,
  `created_at` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_created_at` (`created_at`)
) ENGINE=InnoDB;
INSERT INTO `notifications` VALUES (1, '2026-10-08');
CREATE TABLE `views` (
  `id` bigint NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;
INSERT INTO `views` VALUES (1);

SQL;
    }
}
