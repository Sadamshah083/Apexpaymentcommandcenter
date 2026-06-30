<?php

namespace Tests\Unit\Support;

use App\Support\UsPhoneNormalizer;
use PHPUnit\Framework\TestCase;

class UsPhoneNormalizerTest extends TestCase
{
    public function test_normalizes_ten_digit_number(): void
    {
        $this->assertSame('15551234567', UsPhoneNormalizer::normalize('555-123-4567'));
    }

    public function test_normalizes_eleven_digit_with_country_code(): void
    {
        $this->assertSame('15551234567', UsPhoneNormalizer::normalize('+1 (555) 123-4567'));
    }

    public function test_rejects_invalid_lengths(): void
    {
        $this->assertNull(UsPhoneNormalizer::normalize('12345'));
        $this->assertNull(UsPhoneNormalizer::normalize('25551234567'));
    }

    public function test_formats_normalized_number(): void
    {
        $this->assertSame('+1 (555) 123-4567', UsPhoneNormalizer::format('15551234567'));
    }
}
