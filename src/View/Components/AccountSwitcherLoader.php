<?php

namespace AuthService\Helper\View\Components;

use Illuminate\View\Component;

class AccountSwitcherLoader extends Component
{
    public string $authUrl;
    public bool $autoLoad;

    /**
     * Create a new component instance.
     */
    public function __construct(?string $authUrl = null, bool $autoLoad = true)
    {
        $this->authUrl = $authUrl ?? config('authservice.auth_service_base_url');
        $this->autoLoad = $autoLoad;
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render()
    {
        return view('authservice::components.account-switcher-loader');
    }
}
