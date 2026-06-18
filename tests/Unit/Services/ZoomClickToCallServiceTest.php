<?php

namespace Tests\Unit\Services;

use App\Services\Communications\ZoomClickToCallService;
use Tests\TestCase;

class ZoomClickToCallServiceTest extends TestCase
{
    public function test_builds_zoom_phone_call_url(): void
    {
        $service = new ZoomClickToCallService;

        $this->assertSame(
            'zoomphonecall://+15551234567',
            $service->dialUrl('(555) 123-4567')
        );
    }

    public function test_builds_zoom_phone_call_url_with_caller_id(): void
    {
        $service = new ZoomClickToCallService;

        $this->assertSame(
            'zoomphonecall://+15551234567?callerid=%2B15557654321',
            $service->dialUrl('+1 555 123 4567', '+1 (555) 765-4321')
        );
    }

    public function test_returns_null_for_empty_number(): void
    {
        $service = new ZoomClickToCallService;

        $this->assertNull($service->dialUrl('   '));
    }

    public function test_builds_tel_url(): void
    {
        $service = new ZoomClickToCallService;

        $this->assertSame('tel:+15551234567', $service->telUrl('5551234567'));
    }
}
