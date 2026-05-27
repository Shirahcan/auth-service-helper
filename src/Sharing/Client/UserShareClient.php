<?php

namespace AuthService\Helper\Sharing\Client;

use AuthService\Helper\Sharing\Exceptions\UserShareCollisionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class UserShareClient
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $apiKey = null,
    ) {
        $this->baseUrl = $baseUrl ?? (string) config('authservice.auth_service_base_url');
        $this->apiKey = $apiKey ?? (string) config('authservice.auth_service_api_key');
    }

    /**
     * Create a share. On 409 + conflicts in response, returns the ShareResult
     * with $conflict populated (does NOT throw — facade layer decides whether
     * to bubble UserShareCollisionException based on strict mode).
     */
    public function shareUser(
        string $userId,
        string $targetServiceId,
        ?string $intent = null,
        array $grantedRoles = [],
        array $metadata = [],
    ): ShareResult {
        $body = [
            'user_ids' => [$userId],
            'target_service_id' => $targetServiceId,
            'target_roles' => $grantedRoles,
            'metadata' => $intent ? array_merge($metadata, ['intent' => $intent]) : $metadata,
        ];

        $response = $this->http()->post($this->url('auth/user-shares'), $body);
        $data = $response->json();

        if (!empty($data['conflicts'])) {
            $first = $data['conflicts'][0];
            return new ShareResult(
                id: '',
                userId: $userId,
                targetServiceId: $targetServiceId,
                status: 'conflict',
                grantedRoleIds: $grantedRoles,
                metadata: $metadata,
                conflict: ConflictRef::fromArray($first),
            );
        }

        if (empty($data['shares'])) {
            throw new \RuntimeException('Auth-service returned no shares and no conflicts');
        }

        return ShareResult::fromArray($data['shares'][0]);
    }

    public function listOutgoing(array $filters = [], int $perPage = 50): Collection
    {
        $response = $this->http()->get($this->url('auth/user-shares'), array_merge($filters, ['per_page' => $perPage]));
        return collect($response->json('data', []))->map(fn ($s) => ShareResult::fromArray($s));
    }

    public function listIncoming(array $filters = [], int $perPage = 50): Collection
    {
        $response = $this->http()->get($this->url('auth/user-shares/incoming'), array_merge($filters, ['per_page' => $perPage]));
        return collect($response->json('data', []))->map(fn ($s) => ShareResult::fromArray($s));
    }

    public function get(string $shareId): ShareResult
    {
        $response = $this->http()->get($this->url("auth/user-shares/{$shareId}"));
        return ShareResult::fromArray($response->json());
    }

    public function revoke(string $shareId, ?string $reason = null): void
    {
        $this->http()->delete($this->url("auth/user-shares/{$shareId}"), $reason ? ['reason' => $reason] : []);
    }

    public function bulkRevoke(array $params): array
    {
        return $this->http()->post($this->url('auth/user-shares/bulk-revoke'), $params)->json();
    }

    public function listConflicts(array $filters = []): Collection
    {
        $response = $this->http()->get($this->url('auth/user-shares/conflicts'), $filters);
        return collect($response->json('data', []));
    }

    public function getConflict(string $conflictId): array
    {
        return $this->http()->get($this->url("auth/user-shares/conflicts/{$conflictId}"))->json();
    }

    public function resolveCollision(string $conflictId, string $strategy, array $params = []): array
    {
        return $this->http()->post(
            $this->url("auth/user-shares/conflicts/{$conflictId}/resolve"),
            array_merge(['strategy' => $strategy], $params),
        )->json();
    }

    /**
     * Poll until conflict status is non-pending. Throws on timeout.
     */
    public function waitForMergeCompletion(string $conflictId, int $timeoutSeconds = 30, int $pollMillis = 250): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $row = $this->getConflict($conflictId);
            if (($row['status'] ?? 'pending') !== 'pending') {
                return $row;
            }
            usleep($pollMillis * 1000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException("Merge did not complete within {$timeoutSeconds}s for conflict {$conflictId}");
    }

    protected function http()
    {
        return Http::withHeaders([
            'Accept' => 'application/json',
            'X-API-KEY' => $this->apiKey,
        ])->acceptJson();
    }

    protected function url(string $endpoint): string
    {
        return rtrim($this->baseUrl, '/').'/api/v1/'.ltrim($endpoint, '/');
    }
}
