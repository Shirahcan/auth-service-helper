<?php

namespace AuthService\Helper\Sharing\Outbox;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboundShareMessage extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_IN_FLIGHT = 'in_flight';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_RETRY_SCHEDULED = 'retry_scheduled';
    public const STATUS_DEAD_LETTERED = 'dead_lettered';
    public const STATUS_FAILED_PERMANENT = 'failed_permanent';

    public const TERMINAL_STATES = [
        self::STATUS_DELIVERED,
        self::STATUS_DEAD_LETTERED,
        self::STATUS_FAILED_PERMANENT,
    ];

    public const DELIVERABLE_STATES = [
        self::STATUS_QUEUED,
        self::STATUS_RETRY_SCHEDULED,
    ];

    protected $table = 'outbound_share_messages';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'share_id', 'target_service_id', 'peer_slug',
        'intent', 'intent_version', 'idempotency_key',
        'envelope_json', 'signature_header',
        'status', 'attempts', 'last_attempt_at', 'next_retry_at',
        'delivered_at', 'dead_lettered_at',
        'last_error', 'last_response_status',
    ];

    protected $casts = [
        'envelope_json' => 'array',
        'attempts' => 'integer',
        'last_response_status' => 'integer',
        'last_attempt_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'delivered_at' => 'datetime',
        'dead_lettered_at' => 'datetime',
    ];

    public function isDeliverable(): bool
    {
        return in_array($this->status, self::DELIVERABLE_STATES, true);
    }

    public function isInTerminalState(): bool
    {
        return in_array($this->status, self::TERMINAL_STATES, true);
    }

    protected static function newFactory()
    {
        return \Database\Factories\OutboundShareMessageFactory::new();
    }
}
