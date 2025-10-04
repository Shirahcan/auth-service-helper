<?php

namespace AuthService\Helper\View\Components;

use Illuminate\View\Component;

/**
 * Account Avatar Component
 *
 * A compact, clickable avatar component for NAV bars that displays the current user's avatar
 * and synchronizes with the IFRAME account switcher via postMessage protocol.
 *
 * Features:
 * - Displays user avatar (image or initials) when logged in
 * - Shows generic profile icon when not logged in or IFRAME not loaded
 * - Listens for SESSION_CHANGED messages from auth-service IFRAME
 * - Clickable to toggle account switcher or trigger custom actions
 * - Configurable size and styling
 */
class AccountAvatar extends Component
{
    public string $authUrl;
    public int $size;
    public string $containerId;
    public ?string $targetId;
    public ?string $onClick;

    /**
     * Create a new component instance.
     *
     * @param string|null $authUrl Auth service base URL for origin verification
     * @param int $size Avatar diameter in pixels
     * @param string|null $containerId Container element ID
     * @param string|null $targetId Element ID to toggle visibility on click
     * @param string|null $onClick Custom JavaScript function to execute on click
     */
    public function __construct(
        ?string $authUrl = null,
        int $size = 40,
        ?string $containerId = null,
        ?string $targetId = null,
        ?string $onClick = null
    ) {
        $this->authUrl = $authUrl ?? config('authservice.auth_service_base_url') ?? '';
        $this->size = $size;
        $this->containerId = $containerId ?? 'account-avatar-' . uniqid();
        $this->targetId = $targetId;
        $this->onClick = $onClick;
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render()
    {
        return view('authservice::components.account-avatar', [
            'authUrl' => $this->authUrl,
            'size' => $this->size,
            'containerId' => $this->containerId,
            'targetId' => $this->targetId,
            'onClick' => $this->onClick,
        ]);
    }
}
