<?php

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
        $this->assertStringContainsString('data-prep-id="p-1"', $body);
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
