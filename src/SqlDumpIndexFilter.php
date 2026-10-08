<?php

namespace Livijn\LaravelBackupDownloader;

use Generator;
use RuntimeException;

class SqlDumpIndexFilter
{
    private const CHUNK_SIZE = 65536;

    private const MAX_CREATE_SIZE = 1048576;

    private const IDENTIFIER = '`(?:``|[^`\r\n])+`';

    /**
     * Defer ordinary InnoDB indexes in a standard, single-database mysqldump.
     * The input must be seekable so foreign keys can be inspected before writing.
     *
     * @param resource $input
     * @param resource $output
     */
    public function write($input, $output): void
    {
        if (! is_resource($input) || get_resource_type($input) !== 'stream'
            || ! is_resource($output) || get_resource_type($output) !== 'stream') {
            throw new RuntimeException('The SQL dump filter requires input and output streams.');
        }

        if (! stream_get_meta_data($input)['seekable'] || ! @rewind($input)) {
            throw new RuntimeException('The SQL dump filter requires a seekable input stream.');
        }

        [$safe, $requiredColumns] = $this->inspect($input);

        if (! @rewind($input)) {
            throw new RuntimeException('Could not rewind the SQL dump.');
        }

        $deferred = [];

        if (! $safe) {
            while (($chunk = $this->read($input)) !== null) {
                $this->writeAll($output, $chunk);
            }

            return;
        }

        foreach ($this->segments($input) as $segment) {
            if ($segment['kind'] !== 'create') {
                $this->writeAll($output, $segment['text']);

                continue;
            }

            $table = $this->parseCreate($segment['text']);

            if ($table === null || ! $table['supported']) {
                $this->writeAll($output, $segment['text']);

                continue;
            }

            $removed = [];

            foreach ($table['indexes'] as $position => $index) {
                if (! $this->supportsRequiredColumns($index['columns'], $requiredColumns[$table['name']] ?? [])) {
                    $removed[$position] = $index['definition'];
                }
            }

            if ($removed === []) {
                $this->writeAll($output, $segment['text']);

                continue;
            }

            $retained = array_diff_key($table['clauses'], $removed);
            $last = array_key_last($retained);
            $this->writeAll($output, $table['header']);

            foreach ($retained as $position => $clause) {
                $this->writeAll($output, $clause['indent'].$clause['definition']
                    .($position === $last ? '' : ',').$clause['newline']);
            }

            $this->writeAll($output, $table['footer']);
            $deferred[] = 'ALTER TABLE '.$table['quotedName'].' '
                .implode(', ', array_map(fn (string $definition): string => 'ADD '.$definition, $removed)).";\n";
        }

        if ($deferred !== []) {
            $this->writeAll($output, "\n");

            foreach ($deferred as $statement) {
                $this->writeAll($output, $statement);
            }
        }
    }

    private function inspect($input): array
    {
        $safe = true;
        $requiredColumns = [];
        $createdTables = [];
        $referencedTables = [];

        foreach ($this->segments($input) as $segment) {
            if ($segment['kind'] === 'invalid') {
                $safe = false;

                continue;
            }

            if ($segment['kind'] === 'create') {
                $table = $this->parseCreate($segment['text']);

                if ($table === null || $table['unsafeReferences']) {
                    $safe = false;

                    continue;
                }

                if (isset($createdTables[$table['name']])) {
                    $safe = false;
                }

                $createdTables[$table['name']] = true;

                foreach ($table['autoIncrementColumns'] as $column) {
                    $requiredColumns[$table['name']][] = [$column];
                }

                foreach ($table['foreignKeys'] as $foreignKey) {
                    $requiredColumns[$table['name']][] = $foreignKey['columns'];
                    $requiredColumns[$foreignKey['parent']][] = $foreignKey['parentColumns'];
                    $referencedTables[$foreignKey['parent']] = true;
                }

                continue;
            }

            if (! $segment['start']) {
                continue;
            }

            $line = rtrim($segment['text'], "\r\n");

            if (trim($line) === '' || str_starts_with($line, '-- ')
                || preg_match('/^SET [^\r\n]+;$/', $line)
                || preg_match('/^\/\*!\d+ SET [^\r\n]+ \*\/;$/', $line)
                || preg_match('/^\/\*!\d+ ALTER TABLE '.self::IDENTIFIER.' (?:DISABLE|ENABLE) KEYS \*\/;$/', $line)
                || preg_match('/^LOCK TABLES '.self::IDENTIFIER.' WRITE;$/', $line)
                || $line === 'UNLOCK TABLES;') {
                continue;
            }

            if (preg_match('/^DROP TABLE IF EXISTS ('.self::IDENTIFIER.');$/', $line, $matches)) {
                if (isset($createdTables[$this->unquote($matches[1])])) {
                    $safe = false;
                }

                continue;
            }

            // Only inspect the small prefix of data lines; extended INSERTs may be huge.
            if (preg_match('/^INSERT INTO '.self::IDENTIFIER.' (?:VALUES \(|\('.self::IDENTIFIER.'(?:, ?'.self::IDENTIFIER.')*\) VALUES \()/', $line)) {
                continue;
            }

            // Later DDL, routines, custom delimiters, and database switches can depend
            // on an index or change the meaning of the ALTER statements appended below.
            $safe = false;
        }

        foreach ($referencedTables as $table => $_) {
            if (! isset($createdTables[$table])) {
                $safe = false;
            }
        }

        return [$safe, $requiredColumns];
    }

    /** Yield fixed-size data chunks, buffering only bounded CREATE TABLE blocks. */
    private function segments($input): Generator
    {
        $lineStart = true;
        $create = null;

        while (($chunk = $this->read($input)) !== null) {
            $start = $lineStart;
            $lineStart = str_ends_with($chunk, "\n");

            if ($create === null && $start && str_starts_with($chunk, 'CREATE TABLE ')) {
                if (preg_match('/^CREATE TABLE '.self::IDENTIFIER.' \(\r?\n$/', $chunk)) {
                    $create = $chunk;
                } else {
                    yield ['kind' => 'invalid', 'text' => $chunk, 'start' => $start];
                }

                continue;
            }

            if ($create !== null) {
                $create .= $chunk;

                if (strlen($create) > self::MAX_CREATE_SIZE) {
                    yield ['kind' => 'invalid', 'text' => $create, 'start' => true];
                    $create = null;
                } elseif ($start && preg_match('/^\)[^\r\n]*;\r?\n?$/', $chunk)) {
                    yield ['kind' => 'create', 'text' => $create, 'start' => true];
                    $create = null;
                }

                continue;
            }

            yield ['kind' => 'text', 'text' => $chunk, 'start' => $start];
        }

        if ($create !== null) {
            yield ['kind' => 'invalid', 'text' => $create, 'start' => true];
        }
    }

    private function parseCreate(string $sql): ?array
    {
        $lines = preg_split('/(?<=\n)/', $sql, -1, PREG_SPLIT_NO_EMPTY);
        $header = array_shift($lines);
        $footer = array_pop($lines);

        if (! preg_match('/^CREATE TABLE ('.self::IDENTIFIER.') \(\r?\n$/', $header, $matches)
            || $footer === null || ! preg_match('/^\) ENGINE=([a-zA-Z0-9]+)(?: [^;\r\n]*)?;\r?\n?$/', $footer, $engine)) {
            return null;
        }

        $table = [
            'name' => $this->unquote($matches[1]),
            'quotedName' => $matches[1],
            'header' => $header,
            'footer' => $footer,
            'clauses' => [],
            'indexes' => [],
            'foreignKeys' => [],
            'autoIncrementColumns' => [],
            'supported' => strcasecmp($engine[1], 'InnoDB') === 0 && ! preg_match('/\bPARTITION\b/i', $footer),
            'unsafeReferences' => false,
        ];

        foreach ($lines as $position => $line) {
            if (! preg_match('/^([ \t]+)(.*?)(,?)(\r?\n)$/', $line, $clause)) {
                $table['supported'] = false;

                if (preg_match('/\b(?:FOREIGN\s+KEY|REFERENCES)\b/i', $line)) {
                    $table['unsafeReferences'] = true;
                }

                continue;
            }

            $definition = $clause[2];
            $table['clauses'][$position] = ['indent' => $clause[1], 'definition' => $definition, 'newline' => $clause[4]];

            if (($position === count($lines) - 1) === ($clause[3] === ',')) {
                $table['supported'] = false;
            }

            if (preg_match('/^('.self::IDENTIFIER.') (?:bigint|int|integer|smallint|mediumint|tinyint|decimal|numeric|float|double|real|bit|bool|boolean|char|varchar|binary|varbinary|tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob|date|time|datetime|timestamp|year|json|enum|set|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/i', $definition, $column)) {
                if (preg_match('/\bAUTO_INCREMENT\b/i', $definition)) {
                    $table['autoIncrementColumns'][] = strtolower($this->unquote($column[1]));
                }

                continue;
            }

            if (preg_match('/^KEY '.self::IDENTIFIER.' \((.+)\)(?: USING BTREE)?$/', $definition, $index)) {
                $columns = $this->columns($index[1], true);

                if ($columns === null) {
                    $table['supported'] = false;
                } else {
                    $table['indexes'][$position] = ['definition' => $definition, 'columns' => $columns];
                }

                continue;
            }

            if (preg_match('/^(?:PRIMARY KEY|UNIQUE KEY|FULLTEXT KEY|SPATIAL KEY) /', $definition)) {
                continue;
            }

            if (preg_match('/\b(?:FOREIGN\s+KEY|REFERENCES)\b/i', $definition)) {
                if (! preg_match('/^(?:CONSTRAINT '.self::IDENTIFIER.' )?FOREIGN KEY \((.+?)\) REFERENCES ('.self::IDENTIFIER.') \((.+?)\)(?: ON DELETE (?:RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT))?(?: ON UPDATE (?:RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT))?$/', $definition, $foreignKey)) {
                    $table['unsafeReferences'] = true;

                    continue;
                }

                $columns = $this->columns($foreignKey[1]);
                $parentColumns = $this->columns($foreignKey[3]);

                if ($columns === null || $parentColumns === null) {
                    $table['unsafeReferences'] = true;
                } else {
                    $table['foreignKeys'][] = ['columns' => $columns, 'parent' => $this->unquote($foreignKey[2]), 'parentColumns' => $parentColumns];
                }

                continue;
            }

            $table['supported'] = false;
        }

        return $table;
    }

    private function columns(string $definition, bool $index = false): ?array
    {
        $columns = [];

        foreach (explode(',', $definition) as $column) {
            $suffix = $index ? '(?:\(\d+\))?(?: (?:ASC|DESC))?' : '';

            if (! preg_match('/^('.self::IDENTIFIER.')'.$suffix.'$/', trim($column), $matches)) {
                return null;
            }

            $columns[] = strtolower($this->unquote($matches[1]));
        }

        return $columns;
    }

    private function supportsRequiredColumns(array $columns, array $requirements): bool
    {
        foreach ($requirements as $required) {
            if (array_slice($columns, 0, count($required)) === $required) {
                return true;
            }
        }

        return false;
    }

    private function unquote(string $identifier): string
    {
        return str_replace('``', '`', substr($identifier, 1, -1));
    }

    private function read($input): ?string
    {
        $chunk = @fgets($input, self::CHUNK_SIZE + 1);

        if ($chunk === false && ! feof($input)) {
            throw new RuntimeException('Could not read the SQL dump.');
        }

        return $chunk === false ? null : $chunk;
    }

    private function writeAll($output, string $text): void
    {
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $written = @fwrite($output, substr($text, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the filtered SQL dump.');
            }

            $offset += $written;
        }
    }
}
