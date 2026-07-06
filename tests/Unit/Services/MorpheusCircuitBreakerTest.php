<?php

namespace Tests\Unit\Services;

use App\Services\Integrations\MorpheusCircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MorpheusCircuitBreakerTest extends TestCase
{
    public function test_trips_and_blocks_while_open(): void
    {
        Cache::flush();

        $breaker = new MorpheusCircuitBreaker;
        $this->assertFalse($breaker->isOpen());

        $breaker->trip(30);
        $this->assertTrue($breaker->isOpen());

        $this->expectException(\RuntimeException::class);
        $breaker->guard();
    }

    public function test_reports_connection_failures(): void
    {
        Cache::flush();

        $breaker = new MorpheusCircuitBreaker;
        $breaker->reportFailure(new ConnectionException('Connection timed out'));

        $this->assertTrue($breaker->isOpen());
    }
}
