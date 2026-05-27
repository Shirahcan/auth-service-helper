<?php

namespace Tests\Feature\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceSigned;
use AuthService\Helper\Sharing\Prep\Http\Controllers\EmbedController;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

class EmbedControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    public function test_render_returns_handler_html_for_valid_prep(): void
    {
        $row = PrepResource::factory()->create([
            'intent' => 'agreement_sign',
            'student_data' => ['name' => 'Jane Doe'],
            'payload' => ['agreement_slug' => 'visa-rep-agreement'],
        ]);

        $resp = app(EmbedController::class)->render(Request::create('/'), 'agreement_sign', $row->id);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertStringContainsString('Jane Doe', $resp->getContent());
    }

    public function test_render_404_when_prep_missing(): void
    {
        $resp = app(EmbedController::class)->render(Request::create('/'), 'agreement_sign', '00000000-0000-0000-0000-000000000000');
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function test_render_404_when_intent_mismatch(): void
    {
        $row = PrepResource::factory()->create(['intent' => 'agreement_sign']);
        $resp = app(EmbedController::class)->render(Request::create('/'), 'other_intent', $row->id);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function test_render_404_when_expired(): void
    {
        $row = PrepResource::factory()->create([
            'intent' => 'agreement_sign',
            'expires_at' => now()->subMinute(),
        ]);
        $resp = app(EmbedController::class)->render(Request::create('/'), 'agreement_sign', $row->id);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function test_submit_captures_data_flips_to_signed_dispatches_event(): void
    {
        Event::fake([PrepResourceSigned::class]);
        $row = PrepResource::factory()->create([
            'intent' => 'agreement_sign',
            'student_data' => ['name' => 'Jane Doe'],
        ]);

        $req = Request::create("/sharing/embed/agreement_sign/{$row->id}/submit", 'POST', content: json_encode([
            'signature' => 'Jane Doe',
            'agreed_at' => '2026-05-27T10:00:00Z',
        ]));
        $req->headers->set('Content-Type', 'application/json');

        $resp = app(EmbedController::class)->submit($req, 'agreement_sign', $row->id);
        $this->assertEquals(200, $resp->getStatusCode());

        $row->refresh();
        $this->assertEquals('signed', $row->status);
        $this->assertEquals(['signature' => 'Jane Doe', 'agreed_at' => '2026-05-27T10:00:00Z'], $row->signed_data);

        Event::assertDispatched(PrepResourceSigned::class);
    }

    public function test_submit_422_when_handler_rejects(): void
    {
        $row = PrepResource::factory()->create([
            'intent' => 'agreement_sign',
            'student_data' => ['name' => 'Jane Doe'],
        ]);

        $req = Request::create("/sharing/embed/agreement_sign/{$row->id}/submit", 'POST', content: json_encode([
            'signature' => 'Someone Else',
            'agreed_at' => '2026-05-27T10:00:00Z',
        ]));
        $req->headers->set('Content-Type', 'application/json');

        $resp = app(EmbedController::class)->submit($req, 'agreement_sign', $row->id);
        $this->assertEquals(422, $resp->getStatusCode());
    }

    public function test_submit_409_when_already_signed(): void
    {
        $row = PrepResource::factory()->create([
            'intent' => 'agreement_sign',
            'status' => 'signed',
            'signed_at' => now(),
        ]);
        $resp = app(EmbedController::class)->submit(
            Request::create("/sharing/embed/agreement_sign/{$row->id}/submit", 'POST', content: '{}'),
            'agreement_sign',
            $row->id,
        );
        $this->assertEquals(409, $resp->getStatusCode());
    }
}
