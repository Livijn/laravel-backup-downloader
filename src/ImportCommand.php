<?php

namespace Livijn\LaravelBackupDownloader;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ImportCommand extends Command
{
    protected $signature = 'backup:import
        {--migrate=1 : Run migrations after importing}
        {--skip=views : Comma-separated tables whose INSERT statements should be skipped}';

    protected $description = 'Imports the latest db';

    public function handle(): int
    {
        if (config('app.env') == 'production') {
            $this->error('Nope, not in production.');

            return self::FAILURE;
        }

        $database = config('database.connections.mysql.database');
        $filePath = storage_path('app/private/dbdump.sql');
        $tablesToSkip = $this->tablesToSkip();

        if (! is_file($filePath)) {
            $this->error("Missing dump file: {$filePath}");

            return self::FAILURE;
        }

        $this->call('cache:clear');
        $this->call('horizon:clear');

        if (! $this->prepareDatabase($database)) {
            return self::FAILURE;
        }

        if (! $this->importFile($database, $filePath, $tablesToSkip)) {
            return self::FAILURE;
        }

        if ((int) $this->option('migrate')) {
            $this->call('migrate');
        }

        return self::SUCCESS;
    }

    private function prepareDatabase(string $database): bool
    {
        $this->info("PREPARE DB: {$database}");

        DB::disconnect('mysql');
        $this->killDatabaseConnections($database);

        return $this->runMysql([
            '--execute='.sprintf(
                'DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s;',
                str_replace('`', '``', $database),
                str_replace('`', '``', $database),
                config('database.connections.mysql.charset', 'utf8mb4'),
                config('database.connections.mysql.collation', 'utf8mb4_unicode_ci'),
            ),
        ]);
    }

    private function killDatabaseConnections(string $database): void
    {
        $output = [];
        $exitCode = $this->execMysql([
            '--batch',
            '--skip-column-names',
            '--execute='.sprintf(
                "SELECT ID FROM information_schema.PROCESSLIST WHERE DB = '%s' AND ID <> CONNECTION_ID()",
                str_replace("'", "''", $database),
            ),
        ], $output);

        if ($exitCode !== 0) {
            $this->warn('Could not inspect active database connections before import.');

            return;
        }

        $connectionIds = array_filter(array_map('trim', $output));

        foreach ($connectionIds as $connectionId) {
            if (! ctype_digit($connectionId)) {
                continue;
            }

            $this->runMysql(['--execute=KILL '.$connectionId], false);
        }

        if ($connectionIds !== []) {
            $this->warn('Closed '.count($connectionIds).' active database connection(s).');
        }
    }

    private function importFile(string $database, string $filePath, array $tablesToSkip = []): bool
    {
        $this->info("IMPORT FILE: {$filePath}");

        if ($tablesToSkip !== []) {
            $this->warn('SKIPPING TABLES: '.implode(', ', $tablesToSkip));
        }

        $mysql = $this->mysqlCommand(['--force', $database]);
        $reader = $this->fileReaderCommand($filePath);

        if ($tablesToSkip === []) {
            $imported = $this->runShellCommand("{$reader} | {$mysql}");
        } else {
            $tables = implode('|', array_map(
                fn (string $table): string => preg_quote($table, '/'),
                $tablesToSkip,
            ));

            $imported = $this->runShellCommand(
                "{$reader} | LC_ALL=C grep -avE ".escapeshellarg('^INSERT INTO `('.$tables.')`')." | {$mysql}"
            );
        }

        if ($imported) {
            $this->info('DONE IMPORTING');
        }

        return $imported;
    }

    private function fileReaderCommand(string $filePath): string
    {
        $pv = trim((string) shell_exec('command -v pv 2>/dev/null'));

        if ($pv !== '') {
            return escapeshellarg($pv).' '.escapeshellarg($filePath);
        }

        return 'cat '.escapeshellarg($filePath);
    }

    private function runMysql(array $arguments, bool $reportErrors = true): bool
    {
        $exitCode = $this->passthruWithMysqlEnvironment($this->mysqlCommand($arguments));

        if ($exitCode !== 0 && $reportErrors) {
            $this->error('MySQL command failed with exit code '.$exitCode.'.');
        }

        return $exitCode === 0;
    }

    private function execMysql(array $arguments, array &$output): int
    {
        return $this->withMysqlEnvironment(function () use ($arguments, &$output): int {
            exec($this->mysqlCommand($arguments), $output, $exitCode);

            return $exitCode;
        });
    }

    private function runShellCommand(string $command): bool
    {
        $exitCode = $this->passthruWithMysqlEnvironment('bash -o pipefail -c '.escapeshellarg($command));

        if ($exitCode !== 0) {
            $this->error('Import command failed with exit code '.$exitCode.'.');
        }

        return $exitCode === 0;
    }

    private function passthruWithMysqlEnvironment(string $command): int
    {
        return $this->withMysqlEnvironment(function () use ($command): int {
            passthru($command, $exitCode);

            return $exitCode;
        });
    }

    private function withMysqlEnvironment(callable $callback): int
    {
        $password = config('database.connections.mysql.password');
        $previousPassword = getenv('MYSQL_PWD');

        if ($password !== null && $password !== '') {
            putenv('MYSQL_PWD='.$password);
        }

        try {
            return $callback();
        } finally {
            $previousPassword === false
                ? putenv('MYSQL_PWD')
                : putenv('MYSQL_PWD='.$previousPassword);
        }
    }

    private function mysqlCommand(array $arguments): string
    {
        $config = config('database.connections.mysql');
        $command = ['mysql'];

        if ($socket = Arr::get($config, 'unix_socket')) {
            $command[] = '--socket='.escapeshellarg($socket);
        } else {
            $command[] = '--host='.escapeshellarg((string) Arr::get($config, 'host', '127.0.0.1'));
            $command[] = '--port='.escapeshellarg((string) Arr::get($config, 'port', '3306'));
        }

        $command[] = '--user='.escapeshellarg((string) Arr::get($config, 'username', 'root'));
        $command[] = '--default-character-set='.escapeshellarg((string) Arr::get($config, 'charset', 'utf8mb4'));

        foreach ($arguments as $argument) {
            $command[] = str_contains($argument, '=')
                ? $this->escapeOptionArgument($argument)
                : escapeshellarg($argument);
        }

        return implode(' ', $command);
    }

    private function escapeOptionArgument(string $argument): string
    {
        [$option, $value] = explode('=', $argument, 2);

        return $option.'='.escapeshellarg($value);
    }

    private function tablesToSkip(): array
    {
        $skip = (string) $this->option('skip');

        if ($skip === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $skip)))));
    }
}
