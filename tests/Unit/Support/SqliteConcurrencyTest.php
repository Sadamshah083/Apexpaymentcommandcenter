<?php

namespace Tests\Unit\Support;

use App\Support\SqliteConcurrency;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class SqliteConcurrencyTest extends TestCase
{
    public function test_detects_sqlite_lock_errors(): void
    {
        $exception = new QueryException(
            'sqlite',
            'update "workflow_leads" set "assigned_user_id" = ?',
            [2],
            new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked')
        );

        $this->assertTrue(SqliteConcurrency::causedByLock($exception));
    }

    public function test_retries_until_callback_succeeds(): void
    {
        $attempts = 0;

        $result = SqliteConcurrency::retry(function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                throw new QueryException(
                    'sqlite',
                    'update "workflow_leads" set "assigned_user_id" = ?',
                    [2],
                    new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked')
                );
            }

            return 'ok';
        }, maxAttempts: 5, sleepMs: 1);

        $this->assertSame('ok', $result);
        $this->assertSame(3, $attempts);
    }
}
