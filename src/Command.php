<?php

namespace Livijn\LaravelBackupDownloader;

use Illuminate\Console\Command as BaseCommand;

class Command extends BaseCommand
{
    public function line($string, $style = null, $verbosity = null)
    {
        $styled = $style ? "<$style>$string</$style>" : $string;

        $this->output->writeln(date('[Y-m-d H:i:s] ') . $styled, $this->parseVerbosity($verbosity));
    }
}
