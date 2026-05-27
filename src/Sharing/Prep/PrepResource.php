<?php

namespace AuthService\Helper\Sharing\Prep;

use Database\Factories\PrepResourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrepResource extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PREPARED = 'prepared';
    public const STATUS_SIGNED   = 'signed';
    public const STATUS_PROMOTED = 'promoted';
    public const STATUS_EXPIRED  = 'expired';

    public const TERMINAL_STATES = [self::STATUS_PROMOTED];
    public const GC_ELIGIBLE_STATES = [self::STATUS_PREPARED, self::STATUS_SIGNED, self::STATUS_EXPIRED];

    protected $table = 'prep_resources';

    protected $fillable = [
        'id', 'source_service_id', 'idempotency_key', 'intent', 'intent_version',
        'source_resource', 'student_data', 'payload', 'signed_data', 'return_to',
        'status', 'prepared_at', 'signed_at', 'promoted_at', 'expires_at',
        'permanent_resource_id',
    ];

    protected $casts = [
        'source_resource' => 'array',
        'student_data'    => 'array',
        'payload'         => 'array',
        'signed_data'     => 'array',
        'prepared_at'     => 'datetime',
        'signed_at'       => 'datetime',
        'promoted_at'     => 'datetime',
        'expires_at'      => 'datetime',
    ];

    public function isPrepared(): bool { return $this->status === self::STATUS_PREPARED; }
    public function isSigned(): bool   { return $this->status === self::STATUS_SIGNED; }
    public function isPromoted(): bool { return $this->status === self::STATUS_PROMOTED; }
    public function isExpired(): bool  { return $this->status === self::STATUS_EXPIRED; }
    public function isTerminal(): bool { return in_array($this->status, self::TERMINAL_STATES, true); }

    public function hasExpired(?\DateTimeInterface $now = null): bool
    {
        $now = $now ?? now();
        return $this->expires_at && $this->expires_at->lt($now);
    }

    protected static function newFactory(): PrepResourceFactory
    {
        return PrepResourceFactory::new();
    }
}
