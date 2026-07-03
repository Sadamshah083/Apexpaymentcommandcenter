<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

class MigrateSqliteToMysqlCommand extends Command
{
    protected $signature = 'db:migrate-sqlite-to-mysql
                            {--sqlite-path= : Absolute path to the source SQLite database file}
                            {--mysql-host=127.0.0.1 : Destination MySQL host}
                            {--mysql-port=3306 : Destination MySQL port}
                            {--mysql-database= : Destination MySQL database name}
                            {--mysql-username= : Destination MySQL username}
                            {--mysql-password= : Destination MySQL password}
                            {--mysql-socket= : Destination MySQL socket path}
                            {--mysql-ssl-ca= : Destination MySQL SSL CA path}
                            {--chunk=250 : Number of rows to insert per batch}
                            {--force : Run without confirmation}';

    protected $description = 'Copy all tables and rows from SQLite into a MySQL database without changing row data';

    private const SOURCE_CONNECTION = 'sqlite_migration_source';

    private const DESTINATION_CONNECTION = 'mysql_migration_target';

    public function handle(): int
    {
        $sqlitePath = $this->resolveSqlitePath();
        $mysqlDatabase = (string) $this->option('mysql-database');
        $mysqlUsername = (string) $this->option('mysql-username');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($sqlitePath === '' || ! is_file($sqlitePath)) {
            $this->error('The source SQLite database file was not found.');

            return self::FAILURE;
        }

        if ($mysqlDatabase === '' || $mysqlUsername === '') {
            $this->error('Both --mysql-database and --mysql-username are required.');

            return self::FAILURE;
        }

        if (! extension_loaded('pdo_mysql')) {
            $this->error('The pdo_mysql extension is required for the destination connection.');

            return self::FAILURE;
        }

        $this->configureConnections($sqlitePath);

        if (! $this->option('force') && ! $this->confirm(
            "Copy every table from [{$sqlitePath}] into MySQL database [{$mysqlDatabase}]?",
            false
        )) {
            $this->warn('Migration cancelled.');

            return self::SUCCESS;
        }

        try {
            $source = DB::connection(self::SOURCE_CONNECTION);
            $destination = DB::connection(self::DESTINATION_CONNECTION);

            $source->getPdo();
            $destination->getPdo();

            $this->info('Running migrations on the destination MySQL database...');
            Artisan::call('migrate', [
                '--database' => self::DESTINATION_CONNECTION,
                '--force' => true,
            ], $this->output);

            $tables = $this->sourceTables();
            $this->guardDestinationTables($tables);
            $jsonColumns = $this->jsonColumnsByTable($tables);
            $sourceCounts = $this->sourceCounts($tables);

            $this->line('Disabling foreign key checks and clearing destination tables...');
            $destination->statement('SET FOREIGN_KEY_CHECKS=0');
            $this->truncateDestinationTables($tables);

            $insertedCounts = [];
            foreach ($tables as $table) {
                $insertedCounts[$table] = $this->copyTable($table, $jsonColumns[$table] ?? [], $chunkSize);
            }

            $destination->statement('SET FOREIGN_KEY_CHECKS=1');

            $this->verifyCounts($sourceCounts, $insertedCounts);

            $this->info('SQLite data copied to MySQL successfully.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            try {
                DB::connection(self::DESTINATION_CONNECTION)->statement('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
                // Best effort only.
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveSqlitePath(): string
    {
        $path = (string) ($this->option('sqlite-path') ?: config('database.connections.sqlite.database', ''));

        return $path === ':memory:' ? '' : $path;
    }

    private function configureConnections(string $sqlitePath): void
    {
        $sourceConfig = config('database.connections.sqlite', []);
        $sourceConfig['database'] = $sqlitePath;

        $destinationConfig = config('database.connections.mysql', []);
        $destinationConfig['host'] = (string) $this->option('mysql-host');
        $destinationConfig['port'] = (string) $this->option('mysql-port');
        $destinationConfig['database'] = (string) $this->option('mysql-database');
        $destinationConfig['username'] = (string) $this->option('mysql-username');
        $destinationConfig['password'] = (string) $this->option('mysql-password');
        $destinationConfig['unix_socket'] = (string) $this->option('mysql-socket');
        $destinationConfig['options'] = array_filter([
            PDO::MYSQL_ATTR_SSL_CA => (string) $this->option('mysql-ssl-ca'),
        ]);

        Config::set('database.connections.'.self::SOURCE_CONNECTION, $sourceConfig);
        Config::set('database.connections.'.self::DESTINATION_CONNECTION, $destinationConfig);

        DB::purge(self::SOURCE_CONNECTION);
        DB::purge(self::DESTINATION_CONNECTION);
    }

    /**
     * @return list<string>
     */
    private function sourceTables(): array
    {
        $rows = DB::connection(self::SOURCE_CONNECTION)->select("
            SELECT name
            FROM sqlite_master
            WHERE type = 'table'
              AND name NOT LIKE 'sqlite_%'
            ORDER BY name
        ");

        return collect($rows)
            ->map(fn ($row) => (string) $row->name)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $tables
     */
    private function guardDestinationTables(array $tables): void
    {
        foreach ($tables as $table) {
            if (! Schema::connection(self::DESTINATION_CONNECTION)->hasTable($table)) {
                throw new \RuntimeException("Destination MySQL database is missing table [{$table}] after migrations.");
            }
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, list<string>>
     */
    private function jsonColumnsByTable(array $tables): array
    {
        $database = (string) config('database.connections.'.self::DESTINATION_CONNECTION.'.database');
        $jsonColumns = [];

        foreach ($tables as $table) {
            $rows = DB::connection(self::DESTINATION_CONNECTION)->select(
                'SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ?',
                [$database, $table]
            );

            $jsonColumns[$table] = collect($rows)
                ->filter(fn ($row) => strtolower((string) $row->data_type) === 'json')
                ->map(fn ($row) => (string) $row->column_name)
                ->values()
                ->all();
        }

        return $jsonColumns;
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function sourceCounts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) DB::connection(self::SOURCE_CONNECTION)->table($table)->count();
        }

        return $counts;
    }

    /**
     * @param  list<string>  $tables
     */
    private function truncateDestinationTables(array $tables): void
    {
        foreach ($tables as $table) {
            DB::connection(self::DESTINATION_CONNECTION)->statement(sprintf(
                'TRUNCATE TABLE `%s`',
                str_replace('`', '``', $table)
            ));
        }
    }

    /**
     * @param  list<string>  $jsonColumns
     */
    private function copyTable(string $table, array $jsonColumns, int $chunkSize): int
    {
        $source = DB::connection(self::SOURCE_CONNECTION);
        $destination = DB::connection(self::DESTINATION_CONNECTION);
        $total = (int) $source->table($table)->count();

        $this->line("Copying [{$table}] ({$total} rows)...");

        if ($total === 0) {
            return 0;
        }

        $statement = $source->getPdo()->query(sprintf(
            'SELECT * FROM "%s"',
            str_replace('"', '""', $table)
        ));
        $statement->setFetchMode(PDO::FETCH_ASSOC);

        $batch = [];
        $inserted = 0;

        while (($row = $statement->fetch()) !== false) {
            $batch[] = $this->normalizeRow($table, $row, $jsonColumns);

            if (count($batch) >= $chunkSize) {
                $destination->table($table)->insert($batch);
                $inserted += count($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $destination->table($table)->insert($batch);
            $inserted += count($batch);
        }

        return $inserted;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $jsonColumns
     * @return array<string, mixed>
     */
    private function normalizeRow(string $table, array $row, array $jsonColumns): array
    {
        foreach ($jsonColumns as $column) {
            if (! array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            if (! is_string($row[$column]) || ! $this->isValidJson($row[$column])) {
                throw new \RuntimeException("Table [{$table}] has invalid JSON in column [{$column}].");
            }
        }

        return $row;
    }

    private function isValidJson(string $value): bool
    {
        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @param  array<string, int>  $sourceCounts
     * @param  array<string, int>  $insertedCounts
     */
    private function verifyCounts(array $sourceCounts, array $insertedCounts): void
    {
        $mismatches = [];

        foreach ($sourceCounts as $table => $sourceCount) {
            $insertedCount = $insertedCounts[$table] ?? 0;

            if ($sourceCount !== $insertedCount) {
                $mismatches[] = "{$table}: source={$sourceCount}, inserted={$insertedCount}";
            }
        }

        if ($mismatches !== []) {
            throw new \RuntimeException("Row count verification failed:\n- ".implode("\n- ", $mismatches));
        }
    }
}
