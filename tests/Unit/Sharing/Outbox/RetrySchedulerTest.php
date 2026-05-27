<?php

namespace Tests\Unit\Sharing\Outbox;

use AuthService\Helper\Sharing\Outbox\RetryScheduler;
use PHPUnit\Framework\TestCase;

class RetrySchedulerTest extends TestCase
{
    public function test_schedule_for_attempts_1_through_5(): void
    {
        $this->assertEquals(60, RetryScheduler::nextDelayForAttempt(1));
        $this->assertEquals(300, RetryScheduler::nextDelayForAttempt(2));
        $this->assertEquals(1800, RetryScheduler::nextDelayForAttempt(3));
        $this->assertEquals(7200, RetryScheduler::nextDelayForAttempt(4));
        $this->assertEquals(43200, RetryScheduler::nextDelayForAttempt(5));
    }

    public function test_attempts_beyond_five_return_null(): void
    {
        $this->assertNull(RetryScheduler::nextDelayForAttempt(6));
        $this->assertNull(RetryScheduler::nextDelayForAttempt(99));
    }

    public function test_transient_classification_5xx(): void
    {
        foreach ([500, 502, 503, 504] as $s) {
            $this->assertTrue(RetryScheduler::isTransient($s, null), "{$s} should be transient");
        }
    }

    public function test_transient_classification_408_429(): void
    {
        $this->assertTrue(RetryScheduler::isTransient(408, null));
        $this->assertTrue(RetryScheduler::isTransient(429, null));
    }

    public function test_permanent_classification_other_4xx(): void
    {
        foreach ([400, 401, 403, 404, 410, 422] as $s) {
            $this->assertFalse(RetryScheduler::isTransient($s, null), "{$s} should be permanent");
        }
    }

    public function test_2xx_is_not_transient(): void
    {
        $this->assertFalse(RetryScheduler::isTransient(200, null));
    }

    public function test_network_exception_is_transient(): void
    {
        $this->assertTrue(RetryScheduler::isTransient(null, new \RuntimeException('timed out')));
    }
}
