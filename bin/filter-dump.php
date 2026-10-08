<?php

use Livijn\LaravelBackupDownloader\SqlDumpIndexFilter;

require __DIR__.'/../src/SqlDumpIndexFilter.php';

$input = null;
$exitCode = 0;

try {
    if (! isset($argv[1])) {
        throw new RuntimeException('Missing SQL dump path.');
    }

    $input = @fopen($argv[1], 'rb');

    if ($input === false) {
        throw new RuntimeException('Unable to open the SQL dump.');
    }

    (new SqlDumpIndexFilter)->write($input, STDOUT);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Unable to prepare index-deferred import: '.$exception->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    if (is_resource($input)) {
        fclose($input);
    }
}

exit($exitCode);
