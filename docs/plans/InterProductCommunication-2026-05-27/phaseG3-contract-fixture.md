# Phase G3 — Auth-service OpenAPI contract test fixture publishing

**Repo:** `auth-service-helper` (consumes the YAML from `auth-service`)
**Spec section:** §14 (contract row) + §4
**Depends on:** A5 (YAML must exist), C3 (`HandoffTokenClient` to validate)

## Goal

Pin the helper's `HandoffTokenClient` request/response shapes against the OpenAPI fragment published by `auth-service` in A5. The fixture YAML is COPIED into the helper repo (not git-submoduled) so the helper builds without needing the auth-service tree on disk. A `composer pull-contract-fixtures` script re-copies on demand when auth-service updates the spec.

The test uses `league/openapi-psr7-validator` to validate request/response shapes. If that package can't be installed (license / Composer resolution issue), fall back to manual property-presence checks listed at the end of Step 3.

## Files

- **Create:** `tests/Contract/HandoffTokenContractTest.php`
- **Create:** `tests/Contract/fixtures/.gitkeep`
- **Create:** `scripts/pull-contract-fixtures.php`
- **Modify:** `composer.json` (add `league/openapi-psr7-validator` to `require-dev`, add `pull-contract-fixtures` script)

## Steps

### Step 1 — Add the pull script

```php
<?php
// scripts/pull-contract-fixtures.php
// Run via: composer pull-contract-fixtures
//
// Copies the auth-service OpenAPI fragments into tests/Contract/fixtures/
// so the contract test runs against a vendored copy (no live filesystem
// dependency on the auth-service repo at CI time).

declare(strict_types=1);

$authServicePath = getenv('AUTH_SERVICE_PATH')
    ?: dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'auth-service';

$source = $authServicePath
    .DIRECTORY_SEPARATOR.'project'
    .DIRECTORY_SEPARATOR.'docs'
    .DIRECTORY_SEPARATOR.'openapi'
    .DIRECTORY_SEPARATOR.'handoff-tokens.yaml';

$dest = dirname(__DIR__)
    .DIRECTORY_SEPARATOR.'tests'
    .DIRECTORY_SEPARATOR.'Contract'
    .DIRECTORY_SEPARATOR.'fixtures'
    .DIRECTORY_SEPARATOR.'handoff-tokens.yaml';

if (!is_file($source)) {
    fwrite(STDERR, "ERROR: source spec not found at: {$source}\n");
    fwrite(STDERR, "Set AUTH_SERVICE_PATH env var or place auth-service repo as a sibling of auth-service-helper.\n");
    exit(1);
}

if (!is_dir(dirname($dest))) {
    mkdir(dirname($dest), 0775, true);
}

if (!copy($source, $dest)) {
    fwrite(STDERR, "ERROR: failed to copy spec to {$dest}\n");
    exit(1);
}

echo "Copied {$source}\n     → {$dest}\n";
```

Place an empty `tests/Contract/fixtures/.gitkeep` so the directory is tracked but the YAML itself remains in `.gitignore` (or commit it — see Step 4 note).

### Step 2 — Update `composer.json`

Add to `require-dev`:

```json
"league/openapi-psr7-validator": "^0.22",
"guzzlehttp/psr7": "^2.6"
```

Add to `scripts`:

```json
"pull-contract-fixtures": "@php scripts/pull-contract-fixtures.php"
```

### Step 3 — Write the contract test

```php
<?php
// tests/Contract/HandoffTokenContractTest.php

namespace Tests\Contract;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Response;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use PHPUnit\Framework\TestCase;

class HandoffTokenContractTest extends TestCase
{
    private static string $fixturePath;

    public static function setUpBeforeClass(): void
    {
        self::$fixturePath = __DIR__.'/fixtures/handoff-tokens.yaml';

        if (!is_file(self::$fixturePath)) {
            self::markTestSkipped(
                'Run `composer pull-contract-fixtures` to vendor the auth-service OpenAPI fragment.'
            );
        }
    }

    public function test_mint_request_body_matches_openapi_contract(): void
    {
        $validator = (new ValidatorBuilder())->fromYamlFile(self::$fixturePath)->getServerRequestValidator();

        // Shape produced by HandoffTokenClient::mint() — keep this in sync if the client changes.
        $request = new ServerRequest(
            'POST',
            '/api/v1/auth/handoff-tokens',
            ['Content-Type' => 'application/json', 'X-API-KEY' => 'svc-key'],
            json_encode([
                'share_id' => '00000000-0000-0000-0000-000000000001',
                'next_path' => '/cases/abc',
            ]),
        );

        $validator->validate($request);
        $this->assertTrue(true, 'mint request matches contract');
    }

    public function test_mint_response_matches_openapi_contract(): void
    {
        $validator = (new ValidatorBuilder())->fromYamlFile(self::$fixturePath)->getResponseValidator();

        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'token' => str_repeat('a', 40),
                'redirect_url' => 'https://destination.test/auth/handoff?token=abc',
                'expires_at' => '2026-05-27T10:01:00Z',
            ]),
        );

        $validator->validate(
            new OperationAddress('/api/v1/auth/handoff-tokens', 'post'),
            $response,
        );
        $this->assertTrue(true, 'mint 200 response matches contract');
    }

    public function test_exchange_response_matches_openapi_contract(): void
    {
        $validator = (new ValidatorBuilder())->fromYamlFile(self::$fixturePath)->getResponseValidator();

        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'user' => [
                    'uuid' => '00000000-0000-0000-0000-000000000002',
                    'name' => 'Jane Student',
                    'email' => 'jane@example.test',
                ],
                'session_token' => str_repeat('s', 40),
                'share_id' => '00000000-0000-0000-0000-000000000001',
                'next_path' => '/cases/abc',
            ]),
        );

        $validator->validate(
            new OperationAddress('/api/v1/auth/handoff-tokens/{token}/exchange', 'post'),
            $response,
        );
        $this->assertTrue(true, 'exchange 200 response matches contract');
    }

    public function test_fragment_advertises_both_operations(): void
    {
        $spec = \Symfony\Component\Yaml\Yaml::parseFile(self::$fixturePath);
        $this->assertSame(
            'mintHandoffToken',
            $spec['paths']['/api/v1/auth/handoff-tokens']['post']['operationId'],
        );
        $this->assertSame(
            'exchangeHandoffToken',
            $spec['paths']['/api/v1/auth/handoff-tokens/{token}/exchange']['post']['operationId'],
        );
    }
}
```

**Fallback (if `league/openapi-psr7-validator` cannot be installed):** delete the three `*_matches_openapi_contract` tests and keep only `test_fragment_advertises_both_operations` plus add manual assertions iterating `$spec['paths'][…]['post']['requestBody']['content']['application/json']['schema']['required']` and verifying each required field is one the helper actually sends. The OpenAPI YAML must still be vendored.

### Step 4 — Decide: vendor the YAML, or pull-on-demand?

Two valid options — pick ONE in the commit message:

- **Vendored (recommended for CI hermeticity):** commit the copied `handoff-tokens.yaml` alongside `.gitkeep`. CI runs the test without needing the auth-service repo. Developers run `composer pull-contract-fixtures` after auth-service spec changes and commit the refresh.
- **On-demand (lighter repo):** add `tests/Contract/fixtures/*.yaml` to `.gitignore`. CI installs the auth-service repo (e.g. as a Composer Git dependency or via a CI prep step) and runs `composer pull-contract-fixtures` before tests.

The default recommendation here is **vendored** — pin the contract, refresh deliberately. Update `.gitignore` only if you choose on-demand.

### Step 5 — Run + commit

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper

composer require --dev league/openapi-psr7-validator:^0.22 guzzlehttp/psr7:^2.6

# Vendor the fixture (auth-service repo must be at sibling path or AUTH_SERVICE_PATH set).
composer pull-contract-fixtures

vendor/bin/phpunit --filter=HandoffTokenContractTest
# Expected: 4 passed.

git add composer.json composer.lock \
        scripts/pull-contract-fixtures.php \
        tests/Contract/HandoffTokenContractTest.php \
        tests/Contract/fixtures/.gitkeep \
        tests/Contract/fixtures/handoff-tokens.yaml
git commit -m "feat(sharing): phase G3 — OpenAPI contract test against auth-service fragment

Vendors auth-service's docs/openapi/handoff-tokens.yaml into the helper
via 'composer pull-contract-fixtures'. The contract test validates
HandoffTokenClient request + response shapes against the spec using
league/openapi-psr7-validator. CI runs hermetically against the
committed copy; developers refresh the vendored YAML deliberately
when auth-service rev-bumps the spec.

Phase: G3 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Notes for executor

- If `league/openapi-psr7-validator` is incompatible with the helper's PHP 8.2 / illuminate ^12 constraints, use the documented fallback (manual property checks). Don't bring in an alternative library without flagging in the commit message.
- The `AUTH_SERVICE_PATH` env var lets CI place the auth-service repo anywhere; the default assumes the conventional sibling layout under `~/Documents/GitHub`.
- The contract test is independent from G1's in-process round-trip — G1 proves the system works; G3 proves the helper still matches the published spec. Both are necessary.
