<?php

namespace Livijn\LaravelBackupDownloader;

use Illuminate\Console\Command;

class ImportCommand extends Command
{
    protected $signature = 'backup:import {--sql=} {--migrate=1}';

    protected $description = 'Imports the latest db';

    public function handle()
    {
        if (config('app.env') == 'production') {
            $this->error('Nope, not in production.');

            return;
        }

        $this->call('cache:clear');

        $this->call('horizon:clear');

        $database = config('database.connections.mysql.database');

        $this->prepareDatabase($database);

        $this->importFile($database, 'dbdump.sql');

        if ($this->option('sql')) {
            $this->importFile($database, $this->option('sql'));
        }

        if ((int) $this->option('migrate')) {
            $this->call('migrate');
        }
    }

    private function prepareDatabase(string $database): void
    {
        $this->info("PREPARE DB: {$database}");
        echo exec("mysql -u root -e 'DROP DATABASE `$database`'");
        echo exec("mysql -u root -e 'CREATE DATABASE `$database`'");
    }

    private function importFile(string $database, string $file)
    {
        $this->info("IMPORT FILE: {$file}");
        echo exec("mysql -u root {$database} --force < storage/backups/{$file}");
    }
}
