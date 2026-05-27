<?php

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the HandoffTokenClient request/response shapes against the auth-service
 * OpenAPI fragment (vendored at tests/Contract/fixtures/handoff-tokens.yaml).
 * Re-pull the fixture with `composer pull-contract-fixtures` when auth-service
 * publishes a new spec.
 *
 * This is a lightweight shape-check, not a full OpenAPI validator. If you need
 * stricter validation, add league/openapi-psr7-validator and replace the
 * manual assertions with $validator->validate(...).
 */
class HandoffTokenContractTest extends TestCase
{
    private array $spec;

    protected function setUp(): void
    {
        parent::setUp();
        $path = __DIR__ . '/fixtures/handoff-tokens.yaml';
        if (!is_file($path)) {
            $this->markTestSkipped(
                "OpenAPI fixture missing at {$path}. Run `composer pull-contract-fixtures`.",
            );
        }
        $this->spec = Yaml::parseFile($path);
    }

    public function test_spec_declares_mint_handoff_token_path(): void
    {
        $this->assertArrayHasKey('/api/v1/auth/handoff-tokens', $this->spec['paths']);
        $this->assertArrayHasKey('post', $this->spec['paths']['/api/v1/auth/handoff-tokens']);
        $this->assertEquals(
            'mintHandoffToken',
            $this->spec['paths']['/api/v1/auth/handoff-tokens']['post']['operationId'] ?? null,
        );
    }

    public function test_mint_request_requires_share_id(): void
    {
        $op = $this->spec['paths']['/api/v1/auth/handoff-tokens']['post'];
        $schema = $op['requestBody']['content']['application/json']['schema'];
        $this->assertContains('share_id', $schema['required']);
        $this->assertEquals('string', $schema['properties']['share_id']['type']);
        $this->assertEquals('uuid', $schema['properties']['share_id']['format'] ?? null);
    }

    public function test_mint_response_shape_matches_HandoffMintResult(): void
    {
        $op = $this->spec['paths']['/api/v1/auth/handoff-tokens']['post'];
        $schema = $op['responses']['200']['content']['application/json']['schema'];

        // HandoffMintResult::fromArray expects token, redirect_url, expires_at
        $this->assertContains('token', $schema['required']);
        $this->assertContains('redirect_url', $schema['required']);
        $this->assertContains('expires_at', $schema['required']);
    }

    public function test_spec_declares_exchange_path(): void
    {
        $this->assertArrayHasKey('/api/v1/auth/handoff-tokens/{token}/exchange', $this->spec['paths']);
        $this->assertArrayHasKey('post', $this->spec['paths']['/api/v1/auth/handoff-tokens/{token}/exchange']);
    }

    public function test_exchange_response_shape_matches_HandoffExchangeResult(): void
    {
        $op = $this->spec['paths']['/api/v1/auth/handoff-tokens/{token}/exchange']['post'];
        $schema = $op['responses']['200']['content']['application/json']['schema'];

        // HandoffExchangeResult::fromArray expects user, session_token, share_id
        $required = $schema['required'] ?? [];
        $this->assertContains('user', $required);
        $this->assertContains('session_token', $required);
        $this->assertContains('share_id', $required);
        // next_path is documented as optional
        $this->assertArrayHasKey('next_path', $schema['properties']);
    }
}
