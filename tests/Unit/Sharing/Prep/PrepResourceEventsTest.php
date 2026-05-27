<?php

namespace Tests\Unit\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceCreated;
use AuthService\Helper\Sharing\Prep\Events\PrepResourceExpired;
use AuthService\Helper\Sharing\Prep\Events\PrepResourcePromoted;
use AuthService\Helper\Sharing\Prep\Events\PrepResourceSigned;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Orchestra\Testbench\TestCase;

class PrepResourceEventsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    public function test_each_event_exposes_the_prep_resource(): void
    {
        $row = new PrepResource(['id' => 'p-1', 'intent' => 'agreement_sign']);

        $this->assertSame($row, (new PrepResourceCreated($row))->prep);
        $this->assertSame($row, (new PrepResourceSigned($row))->prep);
        $this->assertSame($row, (new PrepResourcePromoted($row, 'perm-1'))->prep);
        $this->assertEquals('perm-1', (new PrepResourcePromoted($row, 'perm-1'))->permanentResourceId);
        $this->assertSame($row, (new PrepResourceExpired($row))->prep);
    }

    public function test_all_events_use_dispatchable_and_serializes_models(): void
    {
        foreach ([
            PrepResourceCreated::class,
            PrepResourceSigned::class,
            PrepResourcePromoted::class,
            PrepResourceExpired::class,
        ] as $class) {
            $traits = class_uses($class);
            $this->assertContains(\Illuminate\Foundation\Events\Dispatchable::class, $traits, "{$class} missing Dispatchable");
            $this->assertContains(\Illuminate\Queue\SerializesModels::class, $traits, "{$class} missing SerializesModels");
        }
    }
}
