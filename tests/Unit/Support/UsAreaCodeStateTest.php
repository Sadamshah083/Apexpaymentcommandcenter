<?php

namespace Tests\Unit\Support;

use App\Support\UsAreaCodeState;
use PHPUnit\Framework\TestCase;

class UsAreaCodeStateTest extends TestCase
{
    public function test_resolves_state_from_ten_digit_phone(): void
    {
        $this->assertSame('California', UsAreaCodeState::stateNameFromPhone('3105551234'));
        $this->assertSame('New York', UsAreaCodeState::stateNameFromPhone('+1 (212) 555-0100'));
        $this->assertSame('Texas', UsAreaCodeState::stateNameFromPhone('17135550123'));
    }

    public function test_keeps_existing_state(): void
    {
        $this->assertSame('Oregon', UsAreaCodeState::resolve('Oregon', '3105551234'));
        $this->assertSame('California', UsAreaCodeState::resolve(null, '3105551234'));
    }
}
