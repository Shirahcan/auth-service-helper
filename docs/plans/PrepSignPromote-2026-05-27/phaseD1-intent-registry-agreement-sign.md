# Phase D1 — PrepIntentRegistry + AgreementSign builtin

**Repo:** `auth-service-helper`
**Depends on:** B1

## Goal

The handler interface + registry that the embed + promote controllers delegate to. One built-in handler (`AgreementSignHandler`) is shipped as the canonical example AND because the user's primary use case is agreement signing.

The handler is intentionally abstract: it does NOT create the peer's domain models. Instead, it returns a `PromoteResult` that the peer's product code wires up via an event listener on `PrepResourcePromoted`. The handler's job is to validate signature data + map prep → permanent_resource_id; the product owns case creation, agreement-record creation, etc.

## Files

- **Create:** `src/Sharing/Prep/Intents/PrepIntentHandler.php` (interface)
- **Create:** `src/Sharing/Prep/Intents/PrepIntentRegistry.php`
- **Create:** `src/Sharing/Prep/Intents/Builtin/AgreementSignHandler.php`
- **Create:** `src/Sharing/Prep/Intents/Exceptions/InvalidSignatureException.php`
- **Test:** `tests/Unit/Sharing/Prep/PrepIntentRegistryTest.php`
- **Test:** `tests/Unit/Sharing/Prep/AgreementSignHandlerTest.php`

## Steps

### Step 1 — Failing tests

```php
<?php
// tests/Unit/Sharing/Prep/PrepIntentRegistryTest.php
namespace Tests\Unit\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\Intents\PrepIntentHandler;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PromoteResult;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

class PrepIntentRegistryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    public function test_register_and_get_handler(): void
    {
        $reg = new PrepIntentRegistry();
        $h = new TestHandler();
        $reg->register('test', $h);

        $this->assertTrue($reg->has('test'));
        $this->assertSame($h, $reg->get('test'));
    }

    public function test_get_unknown_intent_throws(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new PrepIntentRegistry())->get('missing');
    }

    public function test_agreement_sign_builtin_is_registered_via_service_provider(): void
    {
        /** @var PrepIntentRegistry $reg */
        $reg = $this->app->make(PrepIntentRegistry::class);
        $this->assertTrue($reg->has('agreement_sign'));
    }
}

class TestHandler implements PrepIntentHandler
{
    public static function intentSlug(): string { return 'test'; }
    public static function intentVersion(): string { return '1.0'; }
    public function render(PrepResource $temp): Response { return new Response(''); }
    public function submit(PrepResource $temp, array $signedData): void {}
    public function promote(PrepResource $temp, array $share): PromoteResult
    {
        return new PromoteResult('perm', 'active');
    }
}
```

```php
<?php
// tests/Unit/Sharing/Prep/AgreementSignHandlerTest.php
namespace Tests\Unit\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\Intents\Builtin\AgreementSignHandler;
use AuthService\Helper\Sharing\Prep\Intents\Exceptions\InvalidSignatureException;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Orchestra\Testbench\TestCase;

class AgreementSignHandlerTest extends TestCase
{
    public function test_intent_slug_and_version(): void
    {
        $this->assertEquals('agreement_sign', AgreementSignHandler::intentSlug());
        $this->assertEquals('1.0', AgreementSignHandler::intentVersion());
    }

    public function test_render_returns_html_with_student_name_and_agreement_slug(): void
    {
        $h = new AgreementSignHandler();
        $row = new PrepResource([
            'id' => 'p-1',
            'student_data' => ['name' => 'Jane Doe', 'email' => 'j@x.com'],
            'payload' => ['agreement_slug' => 'visa-rep-agreement'],
            'intent' => 'agreement_sign',
        ]);

        $resp = $h->render($row);
        $this->assertEquals(200, $resp->getStatusCode());
        $body = $resp->getContent();
        $this->assertStringContainsString('Jane Doe', $body);
        $this->assertStringContainsString('visa-rep-agreement', $body);
        $this->assertStringContainsString("data-prep-id=\"p-1\"", $body);  // for the iframe JS
    }

    public function test_submit_rejects_signature_missing_typed_name(): void
    {
        $h = new AgreementSignHandler();
        $row = new PrepResource(['id' => 'p-1', 'student_data' => ['name' => 'Jane Doe']]);

        $this->expectException(InvalidSignatureException::class);
        $h->submit($row, ['agreed_at' => '2026-05-27T10:00:00Z']);
    }

    public function test_submit_rejects_signature_with_mismatched_name(): void
    {
        $h = new AgreementSignHandler();
        $row = new PrepResource(['id' => 'p-1', 'student_data' => ['name' => 'Jane Doe']]);

        $this->expectException(InvalidSignatureException::class);
        $h->submit($row, ['signature' => 'Someone Else', 'agreed_at' => '2026-05-27T10:00:00Z']);
    }

    public function test_submit_accepts_valid_signature(): void
    {
        $h = new AgreementSignHandler();
        $row = new PrepResource(['id' => 'p-1', 'student_data' => ['name' => 'Jane Doe']]);
        $h->submit($row, ['signature' => 'Jane Doe', 'agreed_at' => '2026-05-27T10:00:00Z']);
        $this->expectNotToPerformAssertions();
    }

    public function test_promote_returns_pending_activation_with_uuid(): void
    {
        $h = new AgreementSignHandler();
        $row = new PrepResource([
            'id' => 'p-1',
            'student_data' => ['name' => 'Jane Doe'],
            'signed_data' => ['signature' => 'Jane Doe', 'agreed_at' => '2026-05-27T10:00:00Z'],
        ]);
        $result = $h->promote($row, ['id' => 'sh-1', 'user_id' => 'u-1']);

        $this->assertEquals('pending_activation', $result->state);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-/', $result->permanentResourceId);
    }
}
```

### Step 2 — Implement

```php
<?php
// src/Sharing/Prep/Intents/PrepIntentHandler.php
namespace AuthService\Helper\Sharing\Prep\Intents;

use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PromoteResult;
use Symfony\Component\HttpFoundation\Response;

interface PrepIntentHandler
{
    public static function intentSlug(): string;
    public static function intentVersion(): string;

    /** HTML rendered by GET /sharing/embed/{slug}/{prep_id} for the iframe. */
    public function render(PrepResource $temp): Response;

    /** Validate + persist signature data. Called from POST /embed/{slug}/{id}/submit. Throw InvalidSignatureException to reject. */
    public function submit(PrepResource $temp, array $signedData): void;

    /** Create the permanent resource. $share is the auth-service user_share row (verified by /promote before calling). */
    public function promote(PrepResource $temp, array $share): PromoteResult;
}
```

```php
<?php
// src/Sharing/Prep/Intents/PrepIntentRegistry.php
namespace AuthService\Helper\Sharing\Prep\Intents;

class PrepIntentRegistry
{
    /** @var array<string, PrepIntentHandler> */
    private array $handlers = [];

    public function register(string $slug, PrepIntentHandler $handler): void
    {
        $this->handlers[$slug] = $handler;
    }

    public function has(string $slug): bool
    {
        return isset($this->handlers[$slug]);
    }

    public function get(string $slug): PrepIntentHandler
    {
        if (!isset($this->handlers[$slug])) {
            throw new \OutOfBoundsException("No PrepIntentHandler registered for '{$slug}'");
        }
        return $this->handlers[$slug];
    }

    /** @return string[] */
    public function knownIntents(): array
    {
        return array_keys($this->handlers);
    }
}
```

```php
<?php
// src/Sharing/Prep/Intents/Exceptions/InvalidSignatureException.php
namespace AuthService\Helper\Sharing\Prep\Intents\Exceptions;

class InvalidSignatureException extends \RuntimeException {}
```

```php
<?php
// src/Sharing/Prep/Intents/Builtin/AgreementSignHandler.php
namespace AuthService\Helper\Sharing\Prep\Intents\Builtin;

use AuthService\Helper\Sharing\Prep\Intents\Exceptions\InvalidSignatureException;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentHandler;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PromoteResult;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonical built-in handler for agreement-signing flows.
 *
 * The HTML rendered here is intentionally minimal — peers customise it by
 * registering their own handler with the same slug, OR by listening to
 * PrepResourcePromoted to attach the signed_agreement to their own
 * domain models (cases, contracts, etc.).
 */
class AgreementSignHandler implements PrepIntentHandler
{
    public static function intentSlug(): string { return 'agreement_sign'; }
    public static function intentVersion(): string { return '1.0'; }

    public function render(PrepResource $temp): Response
    {
        $studentName = htmlspecialchars((string) ($temp->student_data['name'] ?? ''), ENT_QUOTES);
        $agreementSlug = htmlspecialchars((string) ($temp->payload['agreement_slug'] ?? ''), ENT_QUOTES);
        $prepId = htmlspecialchars($temp->id, ENT_QUOTES);

        // Minimal HTML — peers SHOULD override this handler with one that
        // renders the actual agreement text + form. This default exists for
        // smoke-testing the iframe handshake.
        $html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>Sign agreement</title></head>
<body data-prep-id="{$prepId}" data-intent="agreement_sign">
  <h1>Agreement: {$agreementSlug}</h1>
  <p>Signer on file: <strong>{$studentName}</strong></p>
  <p><em>This is the default scaffold. Override AgreementSignHandler::render() to display the actual agreement.</em></p>
  <form id="sign-form">
    <label>Type your full name to sign: <input name="signature" required></label>
    <button type="submit">I agree</button>
  </form>
  <script>
  document.getElementById('sign-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const r = await fetch(window.location.pathname + '/submit', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        signature: fd.get('signature'),
        agreed_at: new Date().toISOString(),
      }),
    });
    if (r.ok) {
      window.parent?.postMessage({type: 'prep-signed', prep_id: '{$prepId}'}, '*');
    } else {
      const body = await r.json();
      window.parent?.postMessage({type: 'prep-sign-failed', prep_id: '{$prepId}', error: body.error}, '*');
    }
  });
  </script>
</body></html>
HTML;
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function submit(PrepResource $temp, array $signedData): void
    {
        $signature = $signedData['signature'] ?? null;
        if (!$signature || !is_string($signature)) {
            throw new InvalidSignatureException('signature is required (typed name)');
        }
        $expected = (string) ($temp->student_data['name'] ?? '');
        if ($expected !== '' && strcasecmp(trim($signature), trim($expected)) !== 0) {
            throw new InvalidSignatureException("signature '{$signature}' does not match the student name on file '{$expected}'");
        }
        if (!isset($signedData['agreed_at'])) {
            throw new InvalidSignatureException('agreed_at is required');
        }
    }

    public function promote(PrepResource $temp, array $share): PromoteResult
    {
        // Default behaviour: the prep row becomes the signed_agreement record
        // identifier. Peers override this to mint their own product-side ID.
        $permanentId = (string) Str::uuid();
        return new PromoteResult(
            permanentResourceId: $permanentId,
            state: 'pending_activation',
        );
    }
}
```

### Step 3 — Wire registry into SharingServiceProvider

Append to `register()`:

```php
$this->app->singleton(\AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry::class);
```

Append to `boot()` (afterResolving block):

```php
$this->app->afterResolving(\AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry::class, function ($reg) {
    $reg->register(
        'agreement_sign',
        new \AuthService\Helper\Sharing\Prep\Intents\Builtin\AgreementSignHandler(),
    );
});
```

### Step 4 — Run + commit

```bash
vendor/bin/phpunit tests/Unit/Sharing/Prep/PrepIntentRegistryTest.php tests/Unit/Sharing/Prep/AgreementSignHandlerTest.php
# Expected: 3 + 5 passed.

git add src/Sharing/Prep/Intents/ src/Sharing/SharingServiceProvider.php tests/Unit/Sharing/Prep/
git commit -m "feat(sharing): phase D1 — PrepIntentRegistry + AgreementSign builtin

Handler interface (render / submit / promote), registry singleton, and
the canonical built-in 'agreement_sign' handler. Built-in does minimal
HTML rendering + typed-name signature validation + pending_activation
default state on promote; peers override to wire into their own
domain models via PrepResourcePromoted listeners.

Phase: D1 of docs/plans/PrepSignPromote-2026-05-27/"
```

## Verification checklist

- [ ] Registry singleton resolves via container; `agreement_sign` auto-registered in boot
- [ ] Builtin AgreementSignHandler rejects typed-name mismatch with `InvalidSignatureException`
- [ ] Default HTML posts `prep-signed` postMessage to parent on success (drives F1 iframe protocol)
- [ ] `promote` returns `pending_activation` by default (peer's PrepResourcePromoted listener flips it active)
