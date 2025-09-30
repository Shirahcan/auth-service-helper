<?php

namespace AuthService\Helper\View\Components;

use Illuminate\View\Component;

class AccountSwitcher extends Component
{
    public string $authUrl;
    public string $apiKey;
    public ?string $roles;
    public ?string $id;

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
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render()
    {
        return view('authservice::components.account-switcher');
    }
}
