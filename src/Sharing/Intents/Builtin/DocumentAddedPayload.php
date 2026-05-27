<?php

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
