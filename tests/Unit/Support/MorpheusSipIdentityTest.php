<?php

namespace Tests\Unit\Support;

use App\Support\MorpheusSipIdentity;
use PHPUnit\Framework\TestCase;

class MorpheusSipIdentityTest extends TestCase
{
    public function test_display_name_uses_caller_id_digits(): void
    {
        $this->assertSame('13133851223', MorpheusSipIdentity::displayName('setter_ag_p4w', '13133851223'));
    }

    public function test_display_name_rejects_internal_username(): void
    {
        $this->assertSame('', MorpheusSipIdentity::displayName('setter_ag_p4w', null));
        $this->assertSame('', MorpheusSipIdentity::displayName('admin_super_91a', null));
    }

    public function test_display_name_allows_human_name(): void
    {
        $this->assertSame('Jane Agent', MorpheusSipIdentity::displayName('Jane Agent', null));
    }
}
