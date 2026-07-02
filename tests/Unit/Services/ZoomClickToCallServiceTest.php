<?php

namespace Tests\Unit\Services;

use App\Services\Communications\ZoomClickToCallService;
use Tests\TestCase;

class ZoomClickToCallServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
            'integrations.morpheus.sip_host' => 'apexone.morpheus.cx',
            'integrations.morpheus.sip_params' => 'user=phone',
            'integrations.morpheus.dial_method' => 'sip',
        ]);
    }

    public function test_builds_sip_dial_url_for_e164_number(): void
    {
        $service = new ZoomClickToCallService;

        $this->assertSame(
            'sip:15551234567@apexone.morpheus.cx;user=phone',
            $service->sipUrl('(555) 123-4567')
        );
    }

    public function test_builds_sip_dial_url_for_extension_destination(): void
    {
        $service = new ZoomClickToCallService;

        $this->assertSame(
            'sip:8003@apexone.morpheus.cx',
            $service->sipUrl('8003')
        );
    }

    public function test_preferred_dial_url_uses_sip_when_configured(): void
    {
        $service = new ZoomClickToCallService;

        $this->assertSame(
            'sip:15551234567@apexone.morpheus.cx;user=phone',
            $service->dialUrl('+1 555 123 4567')
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

    public function test_portal_url_defaults_to_https_host(): void
    {
        $service = new ZoomClickToCallService;

        $this->assertSame('https://apexone.morpheus.cx/', $service->portalUrl());
    }
}
