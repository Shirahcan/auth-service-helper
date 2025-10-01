<?php

namespace AuthService\Helper\View\Components;

use AuthService\Helper\Services\AuthServiceClient;
use Illuminate\View\Component;

class AccountSwitcher extends Component
{
    public string $authUrl;
    public string $apiKey;
    public ?string $roles;
    public ?string $id;
    public array $accounts;
    public ?array $activeAccount;
    public ?array $primaryAccount;
    public bool $isLoggedIn;

    /**
     * Create a new component instance.
     */
    public function __construct(
        ?string $authUrl = null,
        ?string $apiKey = null,
        ?string $roles = null,
        ?string $id = null
    ) {
        $this->authUrl = $authUrl ?? config('authservice.auth_service_base_url');
        $this->apiKey = $apiKey ?? config('authservice.auth_service_api_key');
        $this->roles = $roles;
        $this->id = $id ?? 'account-switcher';

        // Fetch session accounts
        $sessionData = $this->fetchSessionAccounts();
        $this->accounts = $sessionData['accounts'] ?? [];
        $this->activeAccount = $sessionData['active_account'] ?? null;
        $this->primaryAccount = $sessionData['primary_account'] ?? null;
        $this->isLoggedIn = count($this->accounts) > 0;
    }

    /**
     * Fetch session accounts from auth service
     */
    private function fetchSessionAccounts(): array
    {
        try {
            $token = session('auth_token');

            if (!$token) {
                return [
                    'accounts' => [],
                    'active_account' => null,
                    'primary_account' => null
                ];
            }

            $client = app(AuthServiceClient::class);
            $response = $client->getSessionAccounts($token);

            if (!($response['success'] ?? false)) {
                return [
                    'accounts' => [],
                    'active_account' => null,
                    'primary_account' => null
                ];
            }

            $session = $response['data']['session'] ?? [];

            return [
                'accounts' => $session['accounts'] ?? [],
                'active_account' => $session['active_account'] ?? null,
                'primary_account' => $session['primary_account'] ?? null
            ];
        } catch (\Exception $e) {
            \Log::warning('Failed to fetch session accounts for AccountSwitcher', [
                'error' => $e->getMessage()
            ]);

            return [
                'accounts' => [],
                'active_account' => null,
                'primary_account' => null
            ];
        }
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render()
    {
        return view('authservice::components.account-switcher', [
            'id' => $this->id,
            'authUrl' => $this->authUrl,
            'apiKey' => $this->apiKey,
            'roles' => $this->roles,
            'accounts' => $this->accounts,
            'activeAccount' => $this->activeAccount,
            'primaryAccount' => $this->primaryAccount,
            'isLoggedIn' => $this->isLoggedIn,
        ]);
    }
}
