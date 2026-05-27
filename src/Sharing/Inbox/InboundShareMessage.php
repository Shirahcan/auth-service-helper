<?php

namespace AuthService\Helper\Sharing\Inbox;

use Database\Factories\InboundShareMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InboundShareMessage extends Model
{
    use HasFactory;
    use HasUuids;

    public $timestamps = false;

    protected $table = 'inbound_share_messages';

    protected $fillable = [
        'id',
        'envelope_version', 'message_id', 'correlation_id',
        'intent', 'intent_version',
        'source_service_id', 'target_service_id', 'user_id',
        'idempotency_key', 'issued_at', 'payload',
        'signature_header', 'headers',
        'received_at', 'processing_status', 'processing_error',
        'dispatched_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'issued_at' => 'datetime',
        'received_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];

    public function isDispatched(): bool
    {
        return $this->processing_status === 'dispatched';
    }

    public function isDuplicate(): bool
    {
        return $this->processing_status === 'duplicate';
    }

    protected static function newFactory(): InboundShareMessageFactory
    {
        return InboundShareMessageFactory::new();
    }
}
