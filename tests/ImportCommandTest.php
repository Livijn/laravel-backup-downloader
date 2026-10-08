<?php

namespace Livijn\LaravelBackupDownloader\Tests;

use Livijn\LaravelBackupDownloader\ImportCommand;
use ReflectionMethod;

class ImportCommandTest extends TestCase
{
    public function test_it_skips_views_by_default(): void
    {
        $command = new ImportCommand;

        $this->assertSame('views', $command->getDefinition()->getOption('skip')->getDefault());
    }

    public function test_it_builds_the_mysql_command_from_database_config(): void
    {
        config()->set('database.connections.mysql.host', 'db.local');
        config()->set('database.connections.mysql.port', '3307');
        config()->set('database.connections.mysql.username', 'app user');
        config()->set('database.connections.mysql.charset', 'utf8mb4');

        $command = $this->mysqlCommand(['--execute=SELECT 1']);

        $this->assertSame(
            "mysql --host='db.local' --port='3307' --user='app user' --default-character-set='utf8mb4' --execute='SELECT 1'",
            $command,
        );
    }

    public function test_it_prefers_the_configured_socket_over_host_and_port(): void
    {
        config()->set('database.connections.mysql.unix_socket', '/tmp/mysql.sock');

        $command = $this->mysqlCommand(['--execute=SELECT 1']);

        $this->assertStringContainsString("--socket='/tmp/mysql.sock'", $command);
        $this->assertStringNotContainsString('--host=', $command);
        $this->assertStringNotContainsString('--port=', $command);
    }

    private function mysqlCommand(array $arguments): string
    {
        $method = new ReflectionMethod(ImportCommand::class, 'mysqlCommand');
        $method->setAccessible(true);

        return $method->invoke(new ImportCommand, $arguments);
    }
}
