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

    public function test_webrtc_sip_domain_derives_pbx_local_from_morpheus_host(): void
    {
        config([
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
            'integrations.morpheus.sip_host' => 'apexone.morpheus.cx',
            'integrations.morpheus.webrtc_sip_domain' => null,
        ]);

        $service = new ZoomClickToCallService;

        $this->assertSame('apexone.pbx.local', $service->webrtcSipDomain());
        $this->assertSame('apexone.morpheus.cx', $service->publicSipHost());
    }

    public function test_prefers_public_host_when_sip_host_is_local_only(): void
    {
        config([
            'integrations.morpheus.host' => 'apexone.morpheus.cx',
            'integrations.morpheus.sip_host' => 'apexone.pbx.local',
        ]);

        $service = new ZoomClickToCallService;

        $this->assertSame('apexone.morpheus.cx', $service->publicSipHost());
        $this->assertSame(
            'sip:15551234567@apexone.morpheus.cx;user=phone',
            $service->sipUrl('(555) 123-4567')
        );
    }

    public function test_resolve_sip_wss_url_uses_direct_morpheus_port_7443(): void
    {
        config(['integrations.morpheus.sip_wss_url' => null]);

        $service = new ZoomClickToCallService;

        $this->assertSame('wss://apexone.morpheus.cx:7443/', $service->resolveSipWssUrl());
    }

    public function test_resolve_sip_wss_url_honors_env_override(): void
    {
        config(['integrations.morpheus.sip_wss_url' => 'wss://apexone.morpheus.cx:7443']);

        $service = new ZoomClickToCallService;

        $this->assertSame('wss://apexone.morpheus.cx:7443/', $service->resolveSipWssUrl());
    }
}
