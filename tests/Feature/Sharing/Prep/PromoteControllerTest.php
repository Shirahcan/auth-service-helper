<?php

namespace Tests\Feature\Sharing\Prep;

use AuthService\Helper\Sharing\Client\UserShareClient;
use AuthService\Helper\Sharing\Prep\Events\PrepResourcePromoted;
use AuthService\Helper\Sharing\Prep\Http\Controllers\PromoteController;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mockery;
use Orchestra\Testbench\TestCase;

class PromoteControllerTest extends TestCase
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_promote_signed_row_creates_permanent_dispatches_event(): void
    {
        Event::fake([PrepResourcePromoted::class]);

        $row = PrepResource::factory()->create([
            'intent' => 'agreement_sign',
            'status' => 'signed',
            'signed_at' => now(),
            'signed_data' => ['signature' => 'Jane Doe', 'agreed_at' => '2026-05-27T10:00:00Z'],
            'student_data' => ['name' => 'Jane Doe'],
        ]);

        $this->fakeShareLookup(shareId: 'sh-1', valid: true);

        $req = $this->buildPromoteRequest($row->id, [
            'operation' => 'promote',
            'share_id'  => 'sh-1',
            'trigger_proof' => ['payment_id' => 'pay-1'],
        ]);

        $resp = app(PromoteController::class)->promote($req, $row->id);
        $this->assertEquals(200, $resp->getStatusCode());
        $body = $resp->getData(true);
        $this->assertNotEmpty($body['permanent_resource_id']);
        $this->assertEquals('pending_activation', $body['state']);

        $row->refresh();
        $this->assertEquals('promoted', $row->status);
        $this->assertNotNull($row->permanent_resource_id);

        Event::assertDispatched(PrepResourcePromoted::class);
    }

    public function test_promote_idempotent_returns_existing_permanent(): void
    {
        $row = PrepResource::factory()->create([
            'status' => 'promoted',
            'permanent_resource_id' => 'perm-99',
            'promoted_at' => now(),
        ]);
        $this->fakeShareLookup(shareId: 'sh-1', valid: true);

        $resp = app(PromoteController::class)->promote(
            $this->buildPromoteRequest($row->id, ['operation' => 'promote', 'share_id' => 'sh-1', 'trigger_proof' => []]),
            $row->id,
        );
        $this->assertEquals(409, $resp->getStatusCode());
        $body = $resp->getData(true);
        $this->assertEquals('already_promoted', $body['error']);
        $this->assertEquals('perm-99', $body['permanent_resource_id']);
    }

    public function test_promote_rejects_unsigned_row(): void
    {
        $row = PrepResource::factory()->create(['status' => 'prepared']);
        $this->fakeShareLookup(shareId: 'sh-1', valid: true);

        $resp = app(PromoteController::class)->promote(
            $this->buildPromoteRequest($row->id, ['operation' => 'promote', 'share_id' => 'sh-1', 'trigger_proof' => []]),
            $row->id,
        );
        $this->assertEquals(409, $resp->getStatusCode());
        $this->assertEquals('not_signed', $resp->getData(true)['error']);
    }

    public function test_promote_rejects_expired_row(): void
    {
        $row = PrepResource::factory()->create([
            'status' => 'signed',
            'signed_at' => now()->subDays(8),
            'expires_at' => now()->subHour(),
        ]);
        $this->fakeShareLookup(shareId: 'sh-1', valid: true);

        $resp = app(PromoteController::class)->promote(
            $this->buildPromoteRequest($row->id, ['operation' => 'promote', 'share_id' => 'sh-1', 'trigger_proof' => []]),
            $row->id,
        );
        $this->assertEquals(404, $resp->getStatusCode());
        $this->assertEquals('prep_expired', $resp->getData(true)['error']);
    }

    public function test_promote_rejects_when_share_id_invalid(): void
    {
        $row = PrepResource::factory()->create([
            'status' => 'signed',
            'signed_at' => now(),
            'student_data' => ['name' => 'Jane Doe'],
            'signed_data' => ['signature' => 'Jane Doe', 'agreed_at' => '2026-05-27T10:00:00Z'],
        ]);
        $this->fakeShareLookup(shareId: 'sh-bad', valid: false);

        $resp = app(PromoteController::class)->promote(
            $this->buildPromoteRequest($row->id, ['operation' => 'promote', 'share_id' => 'sh-bad', 'trigger_proof' => []]),
            $row->id,
        );
        $this->assertEquals(403, $resp->getStatusCode());
        $this->assertEquals('share_invalid', $resp->getData(true)['error']);
    }

    public function test_promote_missing_share_id_returns_400(): void
    {
        $row = PrepResource::factory()->create(['status' => 'signed', 'signed_at' => now()]);
        $resp = app(PromoteController::class)->promote(
            $this->buildPromoteRequest($row->id, ['operation' => 'promote', 'trigger_proof' => []]),
            $row->id,
        );
        $this->assertEquals(400, $resp->getStatusCode());
    }

    private function fakeShareLookup(string $shareId, bool $valid): void
    {
        $mock = Mockery::mock(UserShareClient::class);
        if ($valid) {
            $mock->shouldReceive('get')->with($shareId)->andReturn(new \AuthService\Helper\Sharing\Client\ShareResult(
                id: $shareId,
                userId: 'user-1',
                targetServiceId: 'tgt-1',
                status: 'active',
                grantedRoleIds: [],
                metadata: [],
            ));
        } else {
            // Invalid = share exists but revoked. Real-world auth-service
            // returns a row with status != active; we treat that as 403.
            $mock->shouldReceive('get')->with($shareId)->andReturn(new \AuthService\Helper\Sharing\Client\ShareResult(
                id: $shareId,
                userId: 'user-1',
                targetServiceId: 'tgt-1',
                status: 'revoked',
                grantedRoleIds: [],
                metadata: [],
            ));
        }
        $this->app->instance(UserShareClient::class, $mock);
    }

    private function buildPromoteRequest(string $prepId, array $payload): Request
    {
        $envelope = [
            'envelope_version' => '1',
            'message_id'       => 'msg_' . bin2hex(random_bytes(8)),
            'correlation_id'   => '00000000-0000-0000-0000-000000000010',
            'intent'           => 'prep.promote',
            'intent_version'   => '1.0',
            'source_service_id'=> '11111111-1111-1111-1111-111111111111',
            'target_service_id'=> '22222222-2222-2222-2222-222222222222',
            'user_id'          => '33333333-3333-3333-3333-333333333333',
            'idempotency_key'  => 'studendly:c1:visa-rep',
            'issued_at'        => '2026-05-27T10:00:00Z',
            'payload'          => $payload,
        ];
        $req = Request::create("/api/v1/sharing/prep/{$prepId}/promote", 'POST', content: json_encode($envelope));
        $req->attributes->set('source_service_id', '11111111-1111-1111-1111-111111111111');
        $req->attributes->set('peer_slug', 'studendly');
        $req->headers->set('Content-Type', 'application/json');
        return $req;
    }
}
