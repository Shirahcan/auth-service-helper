# Phase A5 — Routes registration + OpenAPI contract fixture

**Repo:** `auth-service`
**Spec section:** §4 + §14
**Depends on:** A3, A4

## Goal

Move handoff-token routes into a dedicated route block (cleaner than threaded into the existing one), and publish an OpenAPI fragment + contract test fixture that both helpers will pin against.

## Files

- **Modify:** `project/routes/api/v1.php`
- **Create:** `project/docs/openapi/handoff-tokens.yaml`
- **Create:** `project/tests/Contract/HandoffTokenContractTest.php`

## Steps

### Step 1 — Refactor routes into a dedicated block

In `project/routes/api/v1.php`, replace the ad-hoc additions from A3+A4 with:

```php
// Handoff tokens (cross-product seamless-redirect)
Route::prefix('auth/handoff-tokens')
    ->middleware('service-key')
    ->controller(\App\Http\Controllers\Api\V1\HandoffTokenController::class)
    ->group(function () {
        Route::post('/', 'mint')->name('handoff-tokens.mint');
        Route::post('{token}/exchange', 'exchange')->name('handoff-tokens.exchange');
    });
```

### Step 2 — Write the OpenAPI fragment

```yaml
# project/docs/openapi/handoff-tokens.yaml
openapi: 3.1.0
info:
  title: Auth-Service Handoff Tokens
  version: "1.0.0"
paths:
  /api/v1/auth/handoff-tokens:
    post:
      operationId: mintHandoffToken
      security: [{ ServiceKey: [] }]
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [share_id]
              properties:
                share_id: { type: string, format: uuid }
                next_path: { type: string, maxLength: 2048, nullable: true }
      responses:
        "200":
          description: Token minted
          content:
            application/json:
              schema:
                type: object
                required: [token, redirect_url, expires_at]
                properties:
                  token: { type: string, minLength: 40 }
                  redirect_url: { type: string, format: uri }
                  expires_at: { type: string, format: date-time }
        "403": { description: Share not owned by caller }
        "409": { description: Share revoked }
  /api/v1/auth/handoff-tokens/{token}/exchange:
    post:
      operationId: exchangeHandoffToken
      security: [{ ServiceKey: [] }]
      parameters:
        - name: token
          in: path
          required: true
          schema: { type: string }
      responses:
        "200":
          description: Token redeemed
          content:
            application/json:
              schema:
                type: object
                required: [user, session_token, share_id]
                properties:
                  user:
                    type: object
                    required: [uuid, name, email]
                    properties:
                      uuid: { type: string, format: uuid }
                      name: { type: string }
                      email: { type: string, format: email }
                  session_token: { type: string }
                  share_id: { type: string, format: uuid }
                  next_path: { type: string, nullable: true }
        "403": { description: Wrong target service }
        "404": { description: Token unknown }
        "410": { description: Token consumed or expired }
components:
  securitySchemes:
    ServiceKey:
      type: apiKey
      in: header
      name: X-API-KEY
```

### Step 3 — Write the contract test

```php
<?php
// project/tests/Contract/HandoffTokenContractTest.php

namespace Tests\Contract;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class HandoffTokenContractTest extends TestCase
{
    public function test_openapi_fragment_is_valid_yaml_with_expected_paths(): void
    {
        $path = base_path('docs/openapi/handoff-tokens.yaml');
        $this->assertFileExists($path);

        $spec = Yaml::parseFile($path);

        $this->assertArrayHasKey('/api/v1/auth/handoff-tokens', $spec['paths']);
        $this->assertArrayHasKey('/api/v1/auth/handoff-tokens/{token}/exchange', $spec['paths']);
        $this->assertEquals('mintHandoffToken', $spec['paths']['/api/v1/auth/handoff-tokens']['post']['operationId']);
        $this->assertEquals('exchangeHandoffToken', $spec['paths']['/api/v1/auth/handoff-tokens/{token}/exchange']['post']['operationId']);
    }
}
```

### Step 4 — Run + commit

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service\project
php artisan test --filter="HandoffTokenContractTest|HandoffTokenMintTest|HandoffTokenExchangeTest"
# Expected: all green.

git add project/routes/api/v1.php \
        project/docs/openapi/handoff-tokens.yaml \
        project/tests/Contract/HandoffTokenContractTest.php
git commit -m "feat(sharing): phase A5 — routes + OpenAPI contract fixture

Dedicated route block for handoff-tokens; OpenAPI 3.1 fragment
published at docs/openapi/handoff-tokens.yaml; contract test asserts
fragment shape so helpers (G3) can pin against it.

Phase: A5 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Note for G3 (helper-side contract test)

The PHP helper's contract test will copy this YAML at vendor-install time (or via `composer require auth-service-helper:dev` post-install hook) and validate that its generated request shapes match. Phase G3 details that copy mechanism.
