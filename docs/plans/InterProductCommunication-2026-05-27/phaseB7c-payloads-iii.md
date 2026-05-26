# Phase B7c — Built-in payloads III: `Referral`, `Invite`, `RevocationNotice`

**Repo:** `auth-service-helper`
**Spec section:** §6
**Depends on:** B6, B7a (pattern)

## Goal

Final three of the seven built-in intents.

## Files

- **Create:** `src/Sharing/Intents/Builtin/ReferralPayload.php`
- **Create:** `src/Sharing/Intents/Builtin/InvitePayload.php`
- **Create:** `src/Sharing/Intents/Builtin/RevocationNoticePayload.php`
- **Create:** `src/Sharing/Intents/Builtin/schemas/{referral,invite,revocation-notice}.json`
- **Test:** `tests/Unit/Sharing/Intents/Builtin/{Referral,Invite,RevocationNotice}PayloadTest.php`

## Steps

### Step 1 — Failing tests

```php
<?php
// tests/Unit/Sharing/Intents/Builtin/ReferralPayloadTest.php

namespace Tests\Unit\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Builtin\ReferralPayload;
use PHPUnit\Framework\TestCase;

class ReferralPayloadTest extends TestCase
{
    public function test_round_trips(): void
    {
        $raw = ['referral_code' => 'STUDX2026', 'referrer_user_id' => 'uuid-1', 'campaign' => 'spring'];
        $p = ReferralPayload::fromArray($raw);
        $this->assertEquals('STUDX2026', $p->referralCode);
        $this->assertEquals($raw, $p->toArray());
    }
}
```

```php
<?php
// tests/Unit/Sharing/Intents/Builtin/InvitePayloadTest.php

namespace Tests\Unit\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Builtin\InvitePayload;
use PHPUnit\Framework\TestCase;

class InvitePayloadTest extends TestCase
{
    public function test_round_trips(): void
    {
        $raw = ['invitation_message' => 'Welcome', 'granted_roles' => ['client'], 'expires_at' => '2026-06-27T00:00:00Z'];
        $p = InvitePayload::fromArray($raw);
        $this->assertEquals(['client'], $p->grantedRoles);
    }
}
```

```php
<?php
// tests/Unit/Sharing/Intents/Builtin/RevocationNoticePayloadTest.php

namespace Tests\Unit\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Builtin\RevocationNoticePayload;
use PHPUnit\Framework\TestCase;

class RevocationNoticePayloadTest extends TestCase
{
    public function test_round_trips(): void
    {
        $raw = ['reason' => 'admission_withdrawn', 'effective_at' => '2026-05-27T10:00:00Z'];
        $p = RevocationNoticePayload::fromArray($raw);
        $this->assertEquals('admission_withdrawn', $p->reason);
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Intents/Builtin/Referral* tests/Unit/Sharing/Intents/Builtin/Invite* tests/Unit/Sharing/Intents/Builtin/Revocation*
```

### Step 3 — Implement payloads (compact pattern)

```php
<?php
// src/Sharing/Intents/Builtin/ReferralPayload.php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class ReferralPayload implements SharePayload
{
    public function __construct(
        public readonly string $referralCode,
        public readonly ?string $referrerUserId = null,
        public readonly ?string $campaign = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            referralCode: $raw['referral_code'],
            referrerUserId: $raw['referrer_user_id'] ?? null,
            campaign: $raw['campaign'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'referral_code' => $this->referralCode,
            'referrer_user_id' => $this->referrerUserId,
            'campaign' => $this->campaign,
        ], fn($v) => $v !== null);
    }

    public static function intentSlug(): string { return 'referral'; }
    public static function intentVersion(): string { return '1.0'; }
}
```

```php
<?php
// src/Sharing/Intents/Builtin/InvitePayload.php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class InvitePayload implements SharePayload
{
    public function __construct(
        public readonly string $invitationMessage,
        public readonly array $grantedRoles,
        public readonly ?string $expiresAt = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            invitationMessage: $raw['invitation_message'],
            grantedRoles: $raw['granted_roles'] ?? [],
            expiresAt: $raw['expires_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        $arr = [
            'invitation_message' => $this->invitationMessage,
            'granted_roles' => $this->grantedRoles,
        ];
        if ($this->expiresAt) $arr['expires_at'] = $this->expiresAt;
        return $arr;
    }

    public static function intentSlug(): string { return 'invite'; }
    public static function intentVersion(): string { return '1.0'; }
}
```

```php
<?php
// src/Sharing/Intents/Builtin/RevocationNoticePayload.php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class RevocationNoticePayload implements SharePayload
{
    public function __construct(
        public readonly string $reason,
        public readonly string $effectiveAt,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(reason: $raw['reason'], effectiveAt: $raw['effective_at']);
    }

    public function toArray(): array
    {
        return ['reason' => $this->reason, 'effective_at' => $this->effectiveAt];
    }

    public static function intentSlug(): string { return 'revocation_notice'; }
    public static function intentVersion(): string { return '1.0'; }
}
```

Schemas (compact):

```json
// referral.json
{"$schema":"https://json-schema.org/draft-07/schema","type":"object","required":["referral_code"],"properties":{"referral_code":{"type":"string"},"referrer_user_id":{"type":"string"},"campaign":{"type":"string"}},"additionalProperties":true}
```

```json
// invite.json
{"$schema":"https://json-schema.org/draft-07/schema","type":"object","required":["invitation_message","granted_roles"],"properties":{"invitation_message":{"type":"string"},"granted_roles":{"type":"array","items":{"type":"string"}},"expires_at":{"type":"string","format":"date-time"}},"additionalProperties":true}
```

```json
// revocation-notice.json
{"$schema":"https://json-schema.org/draft-07/schema","type":"object","required":["reason","effective_at"],"properties":{"reason":{"type":"string"},"effective_at":{"type":"string","format":"date-time"}},"additionalProperties":true}
```

### Step 4 — Register in SharingServiceProvider

Append to `afterResolving` block:

```php
$reg->register('referral', \AuthService\Helper\Sharing\Intents\Builtin\ReferralPayload::class,
    schemaPath: __DIR__.'/Intents/Builtin/schemas/referral.json');
$reg->register('invite', \AuthService\Helper\Sharing\Intents\Builtin\InvitePayload::class,
    schemaPath: __DIR__.'/Intents/Builtin/schemas/invite.json');
$reg->register('revocation_notice', \AuthService\Helper\Sharing\Intents\Builtin\RevocationNoticePayload::class,
    schemaPath: __DIR__.'/Intents/Builtin/schemas/revocation-notice.json');
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Intents/Builtin/
# Expected: all 7 built-in payload tests pass.

git add src/Sharing/Intents/Builtin/ src/Sharing/SharingServiceProvider.php tests/Unit/Sharing/Intents/Builtin/
git commit -m "feat(sharing): phase B7c — Referral + Invite + RevocationNotice

Final three built-in intents. RevocationNotice is the destination-side
mirror of auth-service's user.share.revoked webhook (received via
D2/D3 webhook controller).

Phase: B7c of docs/plans/InterProductCommunication-2026-05-27/"
```
