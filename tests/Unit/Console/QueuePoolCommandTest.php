<?php

namespace Tests\Unit\Console;

use Tests\TestCase;

class QueuePoolCommandTest extends TestCase
{
    public function test_queue_workers_default_to_two_for_parallel_lead_processing(): void
    {
        $this->assertSame(2, config('queue.workers'));
    }

    public function test_queue_pool_command_is_registered(): void
    {
        $this->artisan('queue:pool --help')
            ->assertExitCode(0);
    }
}
