# Phase B7b — Built-in payloads II: `DocumentAdded`, `StatusUpdate`

**Repo:** `auth-service-helper`
**Spec section:** §6
**Depends on:** B6, B7a (registration pattern)

## Goal

Two more built-in intents covering ongoing destination-relevant updates.

## Files

- **Create:** `src/Sharing/Intents/Builtin/DocumentAddedPayload.php`
- **Create:** `src/Sharing/Intents/Builtin/StatusUpdatePayload.php`
- **Create:** `src/Sharing/Intents/Builtin/schemas/document-added.json`
- **Create:** `src/Sharing/Intents/Builtin/schemas/status-update.json`
- **Test:** `tests/Unit/Sharing/Intents/Builtin/DocumentAddedPayloadTest.php`
- **Test:** `tests/Unit/Sharing/Intents/Builtin/StatusUpdatePayloadTest.php`

## Steps

### Step 1 — Failing tests (same pattern as B7a)

```php
<?php
// tests/Unit/Sharing/Intents/Builtin/DocumentAddedPayloadTest.php

namespace Tests\Unit\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Builtin\DocumentAddedPayload;
use PHPUnit\Framework\TestCase;

class DocumentAddedPayloadTest extends TestCase
{
    public function test_round_trips(): void
    {
        $raw = [
            'document_id' => 'doc_01HZ',
            'kind' => 'transcript',
            'source_url' => 'https://studendly.app/docs/abc',
            'uploaded_at' => '2026-05-27T10:00:00Z',
            'metadata' => ['mime' => 'application/pdf', 'pages' => 4],
        ];
        $p = DocumentAddedPayload::fromArray($raw);
        $this->assertEquals($raw, $p->toArray());
        $this->assertEquals('document_added', DocumentAddedPayload::intentSlug());
    }
}
```

```php
<?php
// tests/Unit/Sharing/Intents/Builtin/StatusUpdatePayloadTest.php

namespace Tests\Unit\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Builtin\StatusUpdatePayload;
use PHPUnit\Framework\TestCase;

class StatusUpdatePayloadTest extends TestCase
{
    public function test_round_trips_with_optional_notes(): void
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

    public function test_round_trips_without_notes(): void
    {
        $raw = [
            'status' => 'x',
            'previous_status' => 'y',
            'effective_at' => '2026-05-27T10:00:00Z',
        ];
        $p = StatusUpdatePayload::fromArray($raw);
        $this->assertNull($p->notes);
    }
}
```

### Step 2 — Run, expect failure

```bash
vendor/bin/pest tests/Unit/Sharing/Intents/Builtin/Document* tests/Unit/Sharing/Intents/Builtin/Status*
```

### Step 3 — Implement

```php
<?php
// src/Sharing/Intents/Builtin/DocumentAddedPayload.php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class DocumentAddedPayload implements SharePayload
{
    public function __construct(
        public readonly string $documentId,
        public readonly string $kind,
        public readonly string $sourceUrl,
        public readonly string $uploadedAt,
        public readonly array $metadata,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            documentId: $raw['document_id'],
            kind: $raw['kind'],
            sourceUrl: $raw['source_url'],
            uploadedAt: $raw['uploaded_at'],
            metadata: $raw['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'kind' => $this->kind,
            'source_url' => $this->sourceUrl,
            'uploaded_at' => $this->uploadedAt,
            'metadata' => $this->metadata,
        ];
    }

    public static function intentSlug(): string { return 'document_added'; }
    public static function intentVersion(): string { return '1.0'; }
}
```

```php
<?php
// src/Sharing/Intents/Builtin/StatusUpdatePayload.php

namespace AuthService\Helper\Sharing\Intents\Builtin;

use AuthService\Helper\Sharing\Intents\Contracts\SharePayload;

final class StatusUpdatePayload implements SharePayload
{
    public function __construct(
        public readonly string $status,
        public readonly string $previousStatus,
        public readonly string $effectiveAt,
        public readonly ?string $notes = null,
    ) {}

    public static function fromArray(array $raw): self
    {
        return new self(
            status: $raw['status'],
            previousStatus: $raw['previous_status'],
            effectiveAt: $raw['effective_at'],
            notes: $raw['notes'] ?? null,
        );
    }

    public function toArray(): array
    {
        $arr = [
            'status' => $this->status,
            'previous_status' => $this->previousStatus,
            'effective_at' => $this->effectiveAt,
        ];
        if ($this->notes !== null) $arr['notes'] = $this->notes;
        return $arr;
    }

    public static function intentSlug(): string { return 'status_update'; }
    public static function intentVersion(): string { return '1.0'; }
}
```

```json
// src/Sharing/Intents/Builtin/schemas/document-added.json
{
  "$schema": "https://json-schema.org/draft-07/schema",
  "type": "object",
  "required": ["document_id", "kind", "source_url", "uploaded_at"],
  "properties": {
    "document_id": { "type": "string" },
    "kind":        { "type": "string" },
    "source_url":  { "type": "string", "format": "uri" },
    "uploaded_at": { "type": "string", "format": "date-time" },
    "metadata":    { "type": "object" }
  },
  "additionalProperties": true
}
```

```json
// src/Sharing/Intents/Builtin/schemas/status-update.json
{
  "$schema": "https://json-schema.org/draft-07/schema",
  "type": "object",
  "required": ["status", "previous_status", "effective_at"],
  "properties": {
    "status":          { "type": "string" },
    "previous_status": { "type": "string" },
    "effective_at":    { "type": "string", "format": "date-time" },
    "notes":           { "type": "string" }
  },
  "additionalProperties": true
}
```

### Step 4 — Register in SharingServiceProvider

Append to the `afterResolving` block from B7a:

```php
$reg->register('document_added', \AuthService\Helper\Sharing\Intents\Builtin\DocumentAddedPayload::class,
    schemaPath: __DIR__.'/Intents/Builtin/schemas/document-added.json');
$reg->register('status_update', \AuthService\Helper\Sharing\Intents\Builtin\StatusUpdatePayload::class,
    schemaPath: __DIR__.'/Intents/Builtin/schemas/status-update.json');
```

### Step 5 — Run + commit

```bash
vendor/bin/pest tests/Unit/Sharing/Intents/Builtin/
# Expected: 4 passed (B7a's 2 + these 2... actually 3, count carefully).

git add src/Sharing/Intents/Builtin/ src/Sharing/SharingServiceProvider.php tests/Unit/Sharing/Intents/Builtin/
git commit -m "feat(sharing): phase B7b — DocumentAdded + StatusUpdate intents

Two more built-in payloads covering ongoing relationship updates
(new docs, admission status changes). Auto-registered.

Phase: B7b of docs/plans/InterProductCommunication-2026-05-27/"
```
