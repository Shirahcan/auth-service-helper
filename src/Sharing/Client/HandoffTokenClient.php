<?php

namespace AuthService\Helper\Sharing\Client;

use AuthService\Helper\Sharing\Exceptions\HandoffTokenInvalidException;
use Illuminate\Support\Facades\Http;

class HandoffTokenClient
{
    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $apiKey = null,
    ) {
        $this->baseUrl = $baseUrl ?? (string) config('authservice.auth_service_base_url');
        $this->apiKey = $apiKey ?? (string) config('authservice.auth_service_api_key');
    }

    public function mint(string $shareId, ?string $nextPath = null): HandoffMintResult
    {
        $body = ['share_id' => $shareId];
        if ($nextPath !== null) $body['next_path'] = $nextPath;

        $response = $this->http()->post($this->url('auth/handoff-tokens'), $body);
        if (!$response->ok()) {
            throw new \RuntimeException(
                "Mint failed: HTTP {$response->status()} — ".$response->body()
            );
        }
        return HandoffMintResult::fromArray($response->json());
    }

    public function exchange(string $rawToken): HandoffExchangeResult
    {
        $response = $this->http()->post($this->url("auth/handoff-tokens/{$rawToken}/exchange"));

        if ($response->ok()) {
            return HandoffExchangeResult::fromArray($response->json());
        }

        $error = $response->json('error', 'unknown');
        $reason = match ($response->status()) {
            404 => HandoffTokenInvalidException::REASON_UNKNOWN,
            403 => HandoffTokenInvalidException::REASON_WRONG_TARGET,
            410 => $error === 'token_expired' ? HandoffTokenInvalidException::REASON_EXPIRED : HandoffTokenInvalidException::REASON_CONSUMED,
            default => HandoffTokenInvalidException::REASON_NETWORK,
        };
        throw new HandoffTokenInvalidException($reason, "Exchange failed: HTTP {$response->status()}");
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
