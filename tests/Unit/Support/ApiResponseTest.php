<?php

namespace Tests\Unit\Support;

use App\Support\Api\ApiResponse;
use PHPUnit\Framework\TestCase;

/**
 * Pure placeholder replacement in ApiResponse (no framework needed).
 */
class ApiResponseTest extends TestCase
{
    public function test_longer_placeholders_are_replaced_before_their_prefixes(): void
    {
        $this->assertSame('LB1 on 7Q-DMA', ApiResponse::replace(':label on :label_aircraft', ['label' => 'LB1', 'label_aircraft' => '7Q-DMA']));
    }
}
