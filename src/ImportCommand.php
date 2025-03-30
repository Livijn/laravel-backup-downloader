<?php

namespace Livijn\LaravelBackupDownloader;

class ImportCommand extends Command
{
    protected $signature = 'backup:import {--migrate=1} {--skip=}';

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
        
        $this->importFile(
            $database, 
            'dbdump.sql',
            $this->option('skip') ?
                explode(',', $this->option('skip')) 
                : [],
        );

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

    private function importFile(string $database, string $file, array $tablesToSkip = [])
    {
        $this->info("IMPORT FILE: {$file}");
        
        if (count($tablesToSkip) === 0) {
            echo exec("mysql -u root {$database} --force < storage/app/private/{$file}");
        } else {
            $this->info("SKIPPING TABLES");
            
            $tablesToSkip = join('|', $tablesToSkip);
            $exp = "INSERT INTO `({$tablesToSkip})`";
            $filePath = storage_path("app/private/{$file}");

            echo exec("cat {$filePath} | grep -vE '{$exp}' | mysql -u root {$database}");
        }
        
        $this->info('DONE IMPORTING');
    }
}
