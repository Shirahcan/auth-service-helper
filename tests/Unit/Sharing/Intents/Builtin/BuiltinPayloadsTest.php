<?php

namespace Tests\Unit\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Builtin\DocumentAddedPayload;
use AuthService\Helper\Sharing\Intents\Builtin\InvitePayload;
use AuthService\Helper\Sharing\Intents\Builtin\ProfileSyncPayload;
use AuthService\Helper\Sharing\Intents\Builtin\ReferralPayload;
use AuthService\Helper\Sharing\Intents\Builtin\RevocationNoticePayload;
use AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload;
use AuthService\Helper\Sharing\Intents\Builtin\StatusUpdatePayload;
use PHPUnit\Framework\TestCase;

class BuiltinPayloadsTest extends TestCase
{
    public function test_service_purchase_round_trips(): void
    {
        $raw = [
            'order_id' => 'studendly:order:88421',
            'service_slug' => 'study_visa',
            'amount_cents' => 40000,
            'currency' => 'CAD',
            'purchased_at' => '2026-05-27T10:00:00Z',
            'items' => [['sku' => 'svc.consult', 'qty' => 1]],
        ];
        $p = ServicePurchasePayload::fromArray($raw);
        $this->assertEquals(40000, $p->amountCents);
        $this->assertEquals($raw, $p->toArray());
        $this->assertEquals('service_purchase', ServicePurchasePayload::intentSlug());
    }

    public function test_profile_sync_round_trips(): void
    {
        $raw = ['fields_changed' => ['phone'], 'snapshot' => ['name' => 'Alice']];
        $p = ProfileSyncPayload::fromArray($raw);
        $this->assertEquals(['phone'], $p->fieldsChanged);
        $this->assertEquals($raw, $p->toArray());
    }

    public function test_document_added_round_trips(): void
    {
        $raw = [
            'document_id' => 'doc_01HZ', 'kind' => 'transcript',
            'source_url' => 'https://studendly.app/docs/abc',
            'uploaded_at' => '2026-05-27T10:00:00Z',
            'metadata' => ['mime' => 'application/pdf'],
        ];
        $p = DocumentAddedPayload::fromArray($raw);
        $this->assertEquals($raw, $p->toArray());
    }

    public function test_status_update_round_trips_with_notes(): void
    {
        $raw = [
            'status' => 'admission_offer_accepted',
            'previous_status' => 'admission_offer_pending',
            'effective_at' => '2026-05-27T10:00:00Z',
            'notes' => 'Student accepted via portal',
        ];
        $p = StatusUpdatePayload::fromArray($raw);
        $this->assertEquals('admission_offer_accepted', $p->status);
        $this->assertEquals($raw, $p->toArray());
    }

    public function test_status_update_round_trips_without_notes(): void
    {
        $raw = ['status' => 'x', 'previous_status' => 'y', 'effective_at' => '2026-05-27T10:00:00Z'];
        $p = StatusUpdatePayload::fromArray($raw);
        $this->assertNull($p->notes);
    }

    public function test_referral_round_trips(): void
    {
        $raw = ['referral_code' => 'STUDX2026', 'referrer_user_id' => 'uuid-1', 'campaign' => 'spring'];
        $p = ReferralPayload::fromArray($raw);
        $this->assertEquals('STUDX2026', $p->referralCode);
        $this->assertEquals($raw, $p->toArray());
    }

    public function test_invite_round_trips(): void
    {
        $raw = ['invitation_message' => 'Welcome', 'granted_roles' => ['client'], 'expires_at' => '2026-06-27T00:00:00Z'];
        $p = InvitePayload::fromArray($raw);
        $this->assertEquals(['client'], $p->grantedRoles);
        $this->assertEquals($raw, $p->toArray());
    }

    public function test_revocation_notice_round_trips(): void
    {
        $raw = ['reason' => 'admission_withdrawn', 'effective_at' => '2026-05-27T10:00:00Z'];
        $p = RevocationNoticePayload::fromArray($raw);
        $this->assertEquals('admission_withdrawn', $p->reason);
        $this->assertEquals($raw, $p->toArray());
    }
}
