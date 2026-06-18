<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class SqliteConcurrency
{
    /**
     * Retry a callback when SQLite reports a transient write lock.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function retry(callable $callback, int $maxAttempts = 12, int $sleepMs = 75)
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            try {
                return $callback();
            } catch (QueryException $e) {
                if (! self::causedByLock($e)) {
                    throw $e;
                }

                $lastException = $e;
                $attempt++;

                if ($attempt >= $maxAttempts) {
                    break;
                }

                usleep($sleepMs * $attempt * 1000);
            }
        }

        throw $lastException ?? new \RuntimeException('SQLite write lock retry limit reached.');
    }

    public static function causedByLock(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'sqlite_busy');
    }

    public static function configureConnection(?string $name = null): void
    {
        $name = $name ?? (string) config('database.default');

        if (config("database.connections.{$name}.driver") !== 'sqlite') {
            return;
        }

        $connection = DB::connection($name);
        $connection->statement('PRAGMA journal_mode=WAL;');
        $connection->statement('PRAGMA busy_timeout='.(int) config('database.connections.'.$name.'.busy_timeout', 30000).';');
        $connection->statement('PRAGMA synchronous=NORMAL;');
        $connection->statement('PRAGMA temp_store=MEMORY;');
    }
}
