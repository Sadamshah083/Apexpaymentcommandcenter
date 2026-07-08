<?php

namespace Tests\Unit\Support;

use App\Support\MorpheusSipIdentity;
use Tests\TestCase;

class MorpheusSipIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.communications.default_caller_id_name' => 'ApexOne Payments']);
    }

    public function test_display_name_uses_business_cnam_when_candidate_is_internal(): void
    {
        $this->assertSame('ApexOne Payments', MorpheusSipIdentity::displayName('setter_ag_p4w', '13133851223'));
    }

    public function test_display_name_rejects_internal_username(): void
    {
        $this->assertSame('ApexOne Payments', MorpheusSipIdentity::displayName('setter_ag_p4w', null));
        $this->assertSame('ApexOne Payments', MorpheusSipIdentity::displayName('admin_super_91a', null));
    }

    public function test_display_name_rejects_digit_only_names(): void
    {
        $this->assertSame('ApexOne Payments', MorpheusSipIdentity::displayName('13133851223', '13133851223'));
    }

    public function test_display_name_rejects_extension_labels(): void
    {
        $this->assertSame('ApexOne Payments', MorpheusSipIdentity::displayName('Billing Ext 1001', '13133851218'));
    }

    public function test_display_name_allows_human_business_name(): void
    {
        $this->assertSame('Jane Agent', MorpheusSipIdentity::displayName('Jane Agent', null));
        $this->assertSame('ApexOne Billing', MorpheusSipIdentity::displayName('ApexOne Billing', '13133851223'));
    }
}
