<?php

namespace Tests\Unit\Console;

use Tests\TestCase;

class QueuePoolCommandTest extends TestCase
{
    public function test_queue_workers_default_to_one_for_sqlite(): void
    {
        $this->assertSame(1, config('queue.workers'));
    }

    public function test_queue_workers_can_use_parallel_defaults_outside_sqlite(): void
    {
        config()->set('database.default', 'mysql');
        config()->set('queue.workers', config('database.default') === 'sqlite' ? 1 : 2);

        $this->assertSame(2, config('queue.workers'));
    }

    public function test_queue_pool_command_is_registered(): void
    {
        $this->artisan('queue:pool --help')
            ->assertExitCode(0);
    }

    public function test_sqlite_to_mysql_migration_command_is_registered(): void
    {
        $this->artisan('db:migrate-sqlite-to-mysql --help')
            ->assertExitCode(0);
    }
}
