<?php

namespace Tests\Unit\Console;

use Tests\TestCase;

class QueuePoolCommandTest extends TestCase
{
    public function test_queue_workers_default_to_one_for_sqlite(): void
    {
        $this->assertSame(1, config('queue.workers'));
    }

    public function test_queue_pool_command_is_registered(): void
    {
        $this->artisan('queue:pool --help')
            ->assertExitCode(0);
    }
}
