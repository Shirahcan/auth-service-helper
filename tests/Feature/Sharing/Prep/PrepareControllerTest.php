<?php

namespace Tests\Feature\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceCreated;
use AuthService\Helper\Sharing\Prep\Http\Controllers\PrepareController;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

class PrepareControllerTest extends TestCase
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

    protected function defineEnvironment($app): void
    {
        $app['config']->set('authservice.sharing.prep.embed_base_url', 'https://portify.test');
    }

    public function test_creates_new_prep_returns_embed_url(): void
    {
        Event::fake([PrepResourceCreated::class]);

        $req = $this->buildRequest([
            'operation' => 'prepare',
            'intent_slug' => 'agreement_sign',
            'source_resource' => ['type' => 'checkout', 'id' => 'c1'],
            'student_data'    => ['external_id' => 'stu_1', 'email' => 'a@b.com', 'name' => 'A'],
            'payload'         => ['agreement_slug' => 'visa-rep-agreement'],
            'return_to'       => 'https://studendly.test/done',
        ], 'studendly:c1:visa-rep');

        $resp = app(PrepareController::class)->prepare($req);

        $this->assertEquals(200, $resp->getStatusCode());
        $body = $resp->getData(true);
        $this->assertNotEmpty($body['prep_id']);
        $this->assertEquals('prepared', $body['status']);
        $this->assertStringStartsWith('https://portify.test/sharing/embed/agreement_sign/', $body['embed_url']);

        $this->assertDatabaseHas('prep_resources', [
            'idempotency_key' => 'studendly:c1:visa-rep',
            'intent' => 'agreement_sign',
            'status' => 'prepared',
        ]);

        Event::assertDispatched(PrepResourceCreated::class);
    }

    public function test_idempotent_returns_existing_no_event(): void
    {
        app(PrepareController::class)->prepare($this->buildRequest(['operation' => 'prepare', 'intent_slug' => 'agreement_sign', 'source_resource' => [], 'student_data' => [], 'payload' => []], 'k'));
        Event::fake([PrepResourceCreated::class]);
        $second = app(PrepareController::class)->prepare($this->buildRequest(['operation' => 'prepare', 'intent_slug' => 'agreement_sign', 'source_resource' => [], 'student_data' => [], 'payload' => []], 'k'));

        $this->assertEquals(1, PrepResource::count());
        $this->assertEquals(200, $second->getStatusCode());
        Event::assertNotDispatched(PrepResourceCreated::class);
    }

    public function test_missing_intent_slug_returns_400(): void
    {
        $req = $this->buildRequest(['operation' => 'prepare', 'source_resource' => [], 'student_data' => [], 'payload' => []], 'k');
        $resp = app(PrepareController::class)->prepare($req);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function test_unknown_intent_slug_returns_400(): void
    {
        $req = $this->buildRequest(['operation' => 'prepare', 'intent_slug' => 'unknown_intent', 'source_resource' => [], 'student_data' => [], 'payload' => []], 'k');
        $resp = app(PrepareController::class)->prepare($req);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    private function buildRequest(array $payload, string $idempotencyKey): Request
    {
        $envelope = [
            'envelope_version' => '1',
            'message_id'       => 'msg_' . bin2hex(random_bytes(8)),
            'correlation_id'   => '00000000-0000-0000-0000-000000000010',
            'intent'           => 'prep.prepare',
            'intent_version'   => '1.0',
            'source_service_id'=> '11111111-1111-1111-1111-111111111111',
            'target_service_id'=> '22222222-2222-2222-2222-222222222222',
            'user_id'          => '33333333-3333-3333-3333-333333333333',
            'idempotency_key'  => $idempotencyKey,
            'issued_at'        => '2026-05-27T10:00:00Z',
            'payload'          => $payload,
        ];
        $req = Request::create('/api/v1/sharing/prep/prepare', 'POST', content: json_encode($envelope));
        $req->attributes->set('source_service_id', '11111111-1111-1111-1111-111111111111');
        $req->attributes->set('peer_slug', 'studendly');
        $req->headers->set('Content-Type', 'application/json');
        return $req;
    }
}
