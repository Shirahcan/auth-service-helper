<?php

namespace AuthService\Helper\Sharing\Prep;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PrepResourceRepository
{
    /**
     * Upsert by (source_service_id, idempotency_key). Returns existing row
     * if one exists for this idempotency_key, never duplicates.
     */
    public function upsert(
        string $sourceServiceId,
        string $idempotencyKey,
        string $intent,
        string $intentVersion,
        array $sourceResource,
        array $studentData,
        array $payload,
        ?string $returnTo,
        int $ttlPreparedHours,
    ): PrepResource {
        $existing = $this->findByIdempotency($sourceServiceId, $idempotencyKey);
        if ($existing) {
            return $existing;
        }

        return PrepResource::create([
            'id'                 => (string) Str::uuid(),
            'source_service_id'  => $sourceServiceId,
            'idempotency_key'    => $idempotencyKey,
            'intent'             => $intent,
            'intent_version'     => $intentVersion,
            'source_resource'    => $sourceResource,
            'student_data'       => $studentData,
            'payload'            => $payload,
            'signed_data'        => null,
            'return_to'          => $returnTo,
            'status'             => PrepResource::STATUS_PREPARED,
            'prepared_at'        => Carbon::now(),
            'expires_at'         => Carbon::now()->addHours($ttlPreparedHours),
        ]);
    }

    public function findByIdempotency(string $sourceServiceId, string $idempotencyKey): ?PrepResource
    {
        return PrepResource::query()
            ->where('source_service_id', $sourceServiceId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function find(string $prepId): ?PrepResource
    {
        return PrepResource::query()->find($prepId);
    }

    public function markSigned(PrepResource $row, array $signedData, int $ttlSignedHours): void
    {
        $row->forceFill([
            'status'      => PrepResource::STATUS_SIGNED,
            'signed_data' => $signedData,
            'signed_at'   => Carbon::now(),
            'expires_at'  => Carbon::now()->addHours($ttlSignedHours),
        ])->save();
    }

    public function markPromoted(PrepResource $row, string $permanentResourceId): void
    {
        $row->forceFill([
            'status'                => PrepResource::STATUS_PROMOTED,
            'permanent_resource_id' => $permanentResourceId,
            'promoted_at'           => Carbon::now(),
        ])->save();
    }

    public function findExpired(int $limit = 500): Collection
    {
        return PrepResource::query()
            ->whereIn('status', PrepResource::GC_ELIGIBLE_STATES)
            ->where('expires_at', '<', Carbon::now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();
    }

    public function delete(PrepResource $row): void
    {
        $row->delete();
    }
}
