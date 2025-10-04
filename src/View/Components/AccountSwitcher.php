<?php

namespace AuthService\Helper\View\Components;

use Illuminate\View\Component;

/**
 * Iframe-based Account Switcher Component
 *
 * This component embeds the secure iframe-based account switcher widget from the auth-service.
 * It provides a simple wrapper for the AccountSwitcherClient JavaScript library with
 * comprehensive session synchronization between the iframe widget and the Laravel application.
 *
 * Previous Implementation: Custom server-rendered component (migrated to iframe on 2025-10-03)
 */
class AccountSwitcher extends Component
{
    public string $authUrl;
    public string $apiKey;
    public string $serviceSlug;
    public string $containerId;
    public bool $autoResize;
    public ?int $minWidth;
    public int $minHeight;
    public ?int $maxHeight;
    public bool $dialogsEnabled;
    public bool $reloadOnSwitch;
    public bool $spaSupport;
    public ?array $roles;

    /**
     * Create a new component instance.
     *
     * @param string|null $authUrl Auth service base URL
     * @param string|null $apiKey Auth service API key
     * @param string|null $serviceSlug Auth service slug
     * @param string|null $containerId Container element ID
     * @param bool $autoResize Enable automatic iframe height adjustment
     * @param int $minHeight Minimum iframe height in pixels
     * @param int|null $maxHeight Maximum iframe height in pixels (null = unlimited)
     * @param bool $dialogsEnabled Enable default Material Design dialogs
     * @param bool $reloadOnSwitch Reload page when account is switched
     * @param bool $spaSupport Enable SPA navigation support
     * @param array|null $roles Optional roles to restrict account switcher access
     */
    public function __construct(
        ?string $authUrl = null,
        ?string $apiKey = null,
        ?string $serviceSlug = null,
        ?string $containerId = null,
        bool $autoResize = true,
        ?int $minWidth = 380,
        int $minHeight = 200,
        ?int $maxHeight = null,
        bool $dialogsEnabled = true,
        bool $reloadOnSwitch = true,
        bool $spaSupport = false,
        ?array $roles = null
    ) {
        $this->authUrl = $authUrl ?? config('authservice.auth_service_base_url') ?? '';
        $this->apiKey = $apiKey ?? config('authservice.auth_service_api_key') ?? '';
        $this->serviceSlug = $serviceSlug ?? config('authservice.service_slug') ?? '';
        $this->containerId = $containerId ?? 'account-switcher-' . uniqid();
        $this->autoResize = $autoResize;
        $this->minWidth = $minWidth;
        $this->minHeight = $minHeight;
        $this->maxHeight = $maxHeight;
        $this->dialogsEnabled = $dialogsEnabled;
        $this->reloadOnSwitch = $reloadOnSwitch;
        $this->spaSupport = $spaSupport;
        $this->roles = $roles ?? config('authservice.login_roles');
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render()
    {
        return view('authservice::components.account-switcher', [
            'authUrl' => $this->authUrl,
            'apiKey' => $this->apiKey,
            'serviceSlug' => $this->serviceSlug,
            'containerId' => $this->containerId,
            'autoResize' => $this->autoResize,
            'minWidth' => $this->minWidth,
            'minHeight' => $this->minHeight,
            'maxHeight' => $this->maxHeight,
            'dialogsEnabled' => $this->dialogsEnabled,
            'reloadOnSwitch' => $this->reloadOnSwitch,
            'spaSupport' => $this->spaSupport,
            'roles' => $this->roles,
        ]);
    }
}
