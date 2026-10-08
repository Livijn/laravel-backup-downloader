<?php

namespace Livijn\LaravelBackupDownloader\Tests;

use Livijn\LaravelBackupDownloader\SqlDumpIndexFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SqlDumpIndexFilterTest extends TestCase
{
    public function test_it_defers_ordinary_indexes_and_repairs_the_last_comma(): void
    {
        $sql = $this->table('items', [
            '`id` bigint NOT NULL',
            '`name` varchar(255) DEFAULT NULL',
            'KEY `name_index` (`name`(32))',
            'PRIMARY KEY (`id`)',
            'KEY `id_name_index` (`id`,`name`) USING BTREE',
        ]);
        $insert = "INSERT INTO `items` VALUES (1,'a,;\\n\\r\\0`KEY`');\n";

        $expected = $this->table('items', [
            '`id` bigint NOT NULL',
            '`name` varchar(255) DEFAULT NULL',
            'PRIMARY KEY (`id`)',
        ]).$insert."\nALTER TABLE `items` ADD KEY `name_index` (`name`(32)), ADD KEY `id_name_index` (`id`,`name`) USING BTREE;\n";

        $this->assertSame($expected, $this->filter($sql.$insert));
    }

    public function test_it_preserves_crlf_and_insert_bytes_including_binary_data(): void
    {
        $sql = str_replace("\n", "\r\n", $this->table('items', [
            '`id` int NOT NULL',
            'PRIMARY KEY (`id`)',
            'KEY `id_index` (`id`)',
        ]));
        $insert = "INSERT INTO `items` VALUES ('".str_repeat("a\0\xff\\n;", 18000)."');\r\n";
        $expected = str_replace("\n", "\r\n", $this->table('items', [
            '`id` int NOT NULL',
            'PRIMARY KEY (`id`)',
        ])).$insert."\nALTER TABLE `items` ADD KEY `id_index` (`id`);\n";

        $this->assertSame($expected, $this->filter($sql.$insert));
    }

    public function test_it_retains_primary_unique_fulltext_and_spatial_indexes(): void
    {
        $clauses = [
            '`id` int NOT NULL',
            '`name` varchar(255) NOT NULL',
            '`location` point NOT NULL',
            'PRIMARY KEY (`id`)',
            'UNIQUE KEY `name_unique` (`name`)',
            'FULLTEXT KEY `name_fulltext` (`name`)',
            'SPATIAL KEY `location_spatial` (`location`)',
            'KEY `ordinary` (`name`)',
        ];

        $this->assertSame(
            $this->table('items', array_slice($clauses, 0, -1))."\nALTER TABLE `items` ADD KEY `ordinary` (`name`);\n",
            $this->filter($this->table('items', $clauses)),
        );
    }

    #[DataProvider('tableOrders')]
    public function test_it_retains_foreign_key_indexes_for_children_and_parents_in_any_order(bool $parentFirst): void
    {
        $parent = $this->table('parents', [
            '`id` int NOT NULL',
            '`code` int NOT NULL',
            '`other` int NOT NULL',
            'PRIMARY KEY (`id`)',
            'KEY `referenced` (`code`,`other`)',
            'KEY `unrelated_parent` (`other`)',
        ]);
        $child = $this->table('children', [
            '`id` int NOT NULL',
            '`parent_code` int NOT NULL',
            '`parent_other` int NOT NULL',
            'PRIMARY KEY (`id`)',
            'KEY `foreign_key_index` (`parent_code`,`parent_other`,`id`)',
            'KEY `wrong_order` (`parent_other`,`parent_code`)',
            'CONSTRAINT `parent_fk` FOREIGN KEY (`parent_code`, `parent_other`) REFERENCES `parents` (`code`, `other`) ON DELETE CASCADE ON UPDATE RESTRICT',
        ]);
        $output = $this->filter($parentFirst ? $parent.$child : $child.$parent);

        $this->assertStringContainsString("  KEY `referenced` (`code`,`other`)\n", $output);
        $this->assertStringContainsString('  KEY `foreign_key_index` (`parent_code`,`parent_other`,`id`),', $output);
        $this->assertStringNotContainsString('ADD KEY `referenced`', $output);
        $this->assertStringNotContainsString('ADD KEY `foreign_key_index`', $output);
        $this->assertStringContainsString('ALTER TABLE `parents` ADD KEY `unrelated_parent` (`other`);', $output);
        $this->assertStringContainsString('ALTER TABLE `children` ADD KEY `wrong_order` (`parent_other`,`parent_code`);', $output);
        $this->assertStringContainsString('CONSTRAINT `parent_fk` FOREIGN KEY', $output);
    }

    public static function tableOrders(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('autoIncrementColumnNames')]
    public function test_it_preserves_the_only_auto_increment_index(string $column): void
    {
        $sql = $this->table('items', [
            "`{$column}` int NOT NULL AUTO_INCREMENT",
            '`other` int NOT NULL',
            "KEY `id_index` (`{$column}`)",
            'KEY `other_index` (`other`)',
        ]);
        $output = $this->filter($sql);

        $this->assertStringContainsString("  KEY `id_index` (`{$column}`)", $output);
        $this->assertStringNotContainsString('ADD KEY `id_index`', $output);
        $this->assertStringContainsString('ALTER TABLE `items` ADD KEY `other_index` (`other`);', $output);
    }

    public static function autoIncrementColumnNames(): array
    {
        return [['id'], ['ID']];
    }

    public function test_it_matches_foreign_key_column_names_without_case_sensitivity(): void
    {
        $sql = $this->table('parents', ['`id` int NOT NULL', 'KEY `parent_index` (`id`)'])
            .$this->table('children', [
                '`id` int NOT NULL',
                'KEY `child_index` (`id`)',
                'CONSTRAINT `parent_fk` FOREIGN KEY (`ID`) REFERENCES `parents` (`ID`)',
            ]);

        $this->assertSame($sql, $this->filter($sql));
    }

    public function test_it_preserves_virtual_json_columns_and_rebuilds_indexes_without_data(): void
    {
        $sql = $this->table('notifications', [
            '`id` int NOT NULL',
            '`data` json DEFAULT NULL',
            '`read_at` varchar(255) GENERATED ALWAYS AS (json_unquote(json_extract(`data`,_utf8mb4\'$.read_at\'))) VIRTUAL',
            'PRIMARY KEY (`id`)',
            'KEY `read_at_index` (`read_at`)',
        ]);
        $output = $this->filter($sql);

        $this->assertStringContainsString('`read_at` varchar(255) GENERATED ALWAYS AS (json_unquote(json_extract(`data`,_utf8mb4\'$.read_at\'))) VIRTUAL,', $output);
        $this->assertStringContainsString('ALTER TABLE `notifications` ADD KEY `read_at_index` (`read_at`);', $output);
        $this->assertStringNotContainsString('INSERT', $output);
    }

    public function test_it_preserves_unsupported_tables_without_disabling_supported_tables(): void
    {
        $supported = $this->table('items', ['`id` int NOT NULL', 'KEY `id_index` (`id`)']);
        $unsupported = $this->table('unsupported', ['`id` int NOT NULL', '`embedding` vector(3)', 'KEY `id_index` (`id`)']);

        $output = $this->filter($unsupported.$supported);

        $this->assertStringStartsWith($unsupported, $output);
        $this->assertStringContainsString('ALTER TABLE `items` ADD KEY `id_index` (`id`);', $output);
        $this->assertStringNotContainsString('ALTER TABLE `unsupported`', $output);
    }

    public function test_it_inspects_foreign_keys_even_when_the_parent_table_has_an_unsupported_column_type(): void
    {
        $parent = $this->table('parents', ['`id` int NOT NULL', '`embedding` vector(3)', 'KEY `parent_index` (`id`)']);
        $child = $this->table('children', [
            '`id` int NOT NULL',
            '`other` int NOT NULL',
            'KEY `child_index` (`id`)',
            'KEY `other_index` (`other`)',
            'CONSTRAINT `parent_fk` FOREIGN KEY (`id`) REFERENCES `parents` (`id`)',
        ]);
        $output = $this->filter($parent.$child);

        $this->assertStringStartsWith($parent, $output);
        $this->assertStringContainsString('  KEY `child_index` (`id`),', $output);
        $this->assertStringContainsString('ALTER TABLE `children` ADD KEY `other_index` (`other`);', $output);
    }

    #[DataProvider('unsupportedDumps')]
    public function test_it_passes_through_dumps_with_unsafe_sql(string $suffix): void
    {
        $sql = $this->table('items', ['`id` int NOT NULL', 'KEY `id_index` (`id`)']).$suffix;

        $this->assertSame($sql, $this->filter($sql));
    }

    public static function unsupportedDumps(): array
    {
        return [
            'custom delimiter' => ["DELIMITER ;;\nCREATE TRIGGER `trigger` BEFORE INSERT ON `items` FOR EACH ROW SET @n=1;;\nDELIMITER ;\n"],
            'database switch' => ["USE `other_database`;\n"],
            'qualified data' => ["INSERT INTO `other_database`.`items` VALUES (1);\n"],
            'later alteration' => ["ALTER TABLE `items` DROP INDEX `id_index`;\n"],
            'later recreation' => ["DROP TABLE IF EXISTS `items`;\n"],
            'query relying on an index' => ["SELECT * FROM `items` FORCE INDEX (`id_index`);\n"],
            'insert from query' => ["INSERT INTO `items` (`id`) SELECT `id` FROM `items` FORCE INDEX (`id_index`);\n"],
            'incomplete create' => ["CREATE TABLE `incomplete` (\n  `id` int NOT NULL,\n"],
            'external foreign key' => ["CREATE TABLE `children` (\n  `id` int NOT NULL,\n  KEY `id_index` (`id`),\n  CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `external` (`id`)\n) ENGINE=InnoDB;\n"],
            'qualified foreign key' => ["CREATE TABLE `children` (\n  `id` int NOT NULL,\n  KEY `id_index` (`id`),\n  CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `other`.`items` (`id`)\n) ENGINE=InnoDB;\n"],
            'unsupported create layout' => ["CREATE TABLE `inline` (`id` int, KEY `id_index` (`id`)) ENGINE=InnoDB;\n"],
            'nonstandard foreign key' => ["CREATE TABLE `children` (\n  `id` int NOT NULL,\n  KEY `id_index` (`id`),\n  constraint `fk` foreign key (`id`) references `items` (`id`)\n) ENGINE=InnoDB;\n"],
        ];
    }

    public function test_it_accepts_the_standard_mysqldump_wrapper_and_an_unterminated_final_newline(): void
    {
        $before = "-- MySQL dump\n\n/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE */;\nDROP TABLE IF EXISTS `items`;\n";
        $after = "LOCK TABLES `items` WRITE;\n/*!40000 ALTER TABLE `items` DISABLE KEYS */;\nINSERT INTO `items` VALUES (1);\n/*!40000 ALTER TABLE `items` ENABLE KEYS */;\nUNLOCK TABLES;\n/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n-- Dump completed";
        $output = $this->filter($before.$this->table('items', ['`id` int NOT NULL', 'KEY `id_index` (`id`)']).$after);

        $this->assertStringStartsWith($before, $output);
        $this->assertStringContainsString($after."\nALTER TABLE `items` ADD KEY `id_index` (`id`);\n", $output);
    }

    public function test_it_streams_large_extended_inserts_with_bounded_memory(): void
    {
        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        fwrite($input, $this->table('items', ['`name` text', 'KEY `name_index` (`name`(64))']));
        fwrite($input, "INSERT INTO `items` VALUES ('");
        $payload = str_repeat('x', 65536);

        for ($i = 0; $i < 256; $i++) {
            fwrite($input, $payload);
        }

        fwrite($input, "');\n");
        $baseline = memory_get_usage();
        memory_reset_peak_usage();

        try {
            (new SqlDumpIndexFilter)->write($input, $output);
            $this->assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $baseline);
            $alter = "\nALTER TABLE `items` ADD KEY `name_index` (`name`(64));\n";
            $expectedLength = strlen($this->table('items', ['`name` text']))
                + strlen("INSERT INTO `items` VALUES ('") + 256 * strlen($payload) + strlen("');\n") + strlen($alter);
            $this->assertSame($expectedLength, ftell($output));
            fseek($output, -strlen($alter), SEEK_END);
            $this->assertSame($alter, stream_get_contents($output));
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function test_it_reports_output_write_failures(): void
    {
        $input = fopen('php://temp', 'w+');
        $output = fopen(__FILE__, 'r');
        fwrite($input, $this->table('items', ['`id` int NOT NULL', 'KEY `id_index` (`id`)']));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Could not write the filtered SQL dump.');
            (new SqlDumpIndexFilter)->write($input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    #[DataProvider('readFailurePasses')]
    public function test_it_reports_read_failures_without_appending_index_statements(int $pass): void
    {
        stream_wrapper_register('sqldumpreadfailure', SqlDumpReadFailureStream::class);
        SqlDumpReadFailureStream::$sql = $this->table('items', ['`id` int NOT NULL', 'KEY `id_index` (`id`)'])
            ."INSERT INTO `items` VALUES ('".str_repeat('x', 100000)."');\n";
        SqlDumpReadFailureStream::$failOnPass = $pass;
        $input = fopen('sqldumpreadfailure://dump', 'r');
        $output = fopen('php://temp', 'w+');

        try {
            try {
                (new SqlDumpIndexFilter)->write($input, $output);
                $this->fail('Expected the SQL dump read failure to be reported.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Could not read the SQL dump.', $exception->getMessage());
            }

            rewind($output);
            $contents = stream_get_contents($output);
            $this->assertStringNotContainsString('ALTER TABLE', $contents);

            if ($pass === 1) {
                $this->assertSame('', $contents);
            }
        } finally {
            fclose($input);
            fclose($output);
            stream_wrapper_unregister('sqldumpreadfailure');
        }
    }

    public static function readFailurePasses(): array
    {
        return [[1], [2]];
    }

    private function table(string $name, array $clauses): string
    {
        return "CREATE TABLE `{$name}` (\n  ".implode(",\n  ", $clauses)."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
    }

    private function filter(string $sql): string
    {
        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        fwrite($input, $sql);

        try {
            (new SqlDumpIndexFilter)->write($input, $output);
            rewind($output);

            return stream_get_contents($output);
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}

/** A seekable source that fails near EOF during either inspection or rewriting. */
class SqlDumpReadFailureStream
{
    public $context;

    public static string $sql;

    public static int $failOnPass;

    private int $position = 0;

    private int $pass = 0;

    public function stream_open($path, $mode, $options, &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        $limit = $this->pass === self::$failOnPass ? strlen(self::$sql) - 3 : strlen(self::$sql);

        if ($this->position >= $limit && $limit < strlen(self::$sql)) {
            return false;
        }

        $text = substr(self::$sql, $this->position, min($count, $limit - $this->position));
        $this->position += strlen($text);

        return $text;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$sql);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        if ($offset !== 0 || $whence !== SEEK_SET) {
            return false;
        }

        $this->position = 0;
        $this->pass++;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }
}
