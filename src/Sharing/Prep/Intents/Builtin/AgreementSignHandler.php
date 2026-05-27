<?php

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
 * Peers override this by registering their own handler with the same slug
 * (or listen to PrepResourcePromoted to plug into their case/agreement
 * domain models).
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
      window.parent && window.parent.postMessage({type: 'prep-signed', prep_id: '{$prepId}'}, '*');
    } else {
      const body = await r.json();
      window.parent && window.parent.postMessage({type: 'prep-sign-failed', prep_id: '{$prepId}', error: body.error}, '*');
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
        // Default: prep row becomes the signed_agreement record identifier.
        // Peers override this to mint their own product-side ID.
        $permanentId = (string) Str::uuid();
        return new PromoteResult(
            permanentResourceId: $permanentId,
            state: 'pending_activation',
        );
    }
}
