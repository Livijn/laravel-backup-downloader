<?php
namespace Livijn\LaravelBackupDownloader;

use Illuminate\Console\Command;

class ImportCommand extends Command
{
    protected $signature = 'backup:import';
    protected $description = 'Imports the latest db';

    public function handle()
    {
        $this->call('cache:clear');

        $this->call('horizon:clear');

        $this->importDatabase();;

        $this->call('migrate');
    }

    private function importDatabase(): void
    {
        $database = config('database.connections.mysql.database');

        $this->info('PREPARE DB');
        echo exec("mysql -u root -e 'DROP DATABASE `$database`'");
        echo exec("mysql -u root -e 'CREATE DATABASE `$database`'");

        $this->info('IMPORT SQL');
        echo exec('mysql -u root '.$database.' --force < storage/backups/dbdump.sql');
    }
}
