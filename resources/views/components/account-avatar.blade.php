{{--
    Account Avatar Component

    A compact, clickable avatar component for NAV bars that displays the current user's avatar
    and synchronizes with the IFRAME account switcher.

    Features:
    - Displays user avatar (image or initials) when logged in
    - Shows generic profile icon when logged out or IFRAME not loaded
    - Listens for SESSION_CHANGED messages from auth-service IFRAME
    - Configurable size and click behavior
--}}

<div id="{{ $containerId }}" class="account-avatar-container" style="
    width: {{ $size }}px;
    height: {{ $size }}px;
    border-radius: 50%;
    background: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    overflow: hidden;
    position: relative;
    flex-shrink: 0;
">
    <!-- Generic profile icon (default state) -->
    <svg id="{{ $containerId }}-icon" width="{{ $size * 0.6 }}" height="{{ $size * 0.6 }}" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transition: opacity 0.2s ease;">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
        <circle cx="12" cy="7" r="4"></circle>
    </svg>

    <!-- Avatar content (hidden by default, shown when logged in) -->
    <div id="{{ $containerId }}-content" style="
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        display: none;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        font-size: {{ $size * 0.4 }}px;
        font-weight: 500;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    "></div>
</div>

<style>
    #{{ $containerId }}:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }

    #{{ $containerId }}:active {
        transform: scale(0.98);
    }
</style>

<script>
(function() {
    'use strict';

    const containerId = '{{ $containerId }}';
    const authUrl = '{{ $authUrl }}';
    const targetId = '{{ $targetId }}';
    const customOnClick = '{{ $onClick }}';

    const container = document.getElementById(containerId);
    const icon = document.getElementById(containerId + '-icon');
    const content = document.getElementById(containerId + '-content');

    let currentUser = null;

    /**
     * Get initials from name (same logic as IFRAME)
     */
    function getInitials(name) {
        if (!name) return '??';
        return name.split(' ')
            .map(n => n[0])
            .join('')
            .toUpperCase()
            .slice(0, 2);
    }

    /**
     * Render avatar content (image or initials)
     */
    function renderAvatarContent(user) {
        if (!user) return '';

        if (user.avatar) {
            // External avatar (e.g., from social login)
            if (user.avatar.startsWith('http')) {
                return `<img src="${user.avatar}" alt="${user.name}" style="width: 100%; height: 100%; object-fit: cover; border-radius: inherit;">`;
            }
            // Local avatar file
            else {
                return `<img src="/storage/${user.avatar}" alt="${user.name}" style="width: 100%; height: 100%; object-fit: cover; border-radius: inherit;">`;
            }
        }

        // Fallback to initials
        return getInitials(user.name);
    }

    /**
     * Update avatar display based on session data
     */
    function updateAvatar(sessionData) {
        if (!sessionData || !sessionData.isAuthenticated || !sessionData.currentUser) {
            // Not logged in - show generic icon
            icon.style.display = 'block';
            content.style.display = 'none';
            container.style.background = '#e5e7eb';
            currentUser = null;
            return;
        }

        // Logged in - show user avatar
        currentUser = sessionData.currentUser;
        const avatarHtml = renderAvatarContent(currentUser);

        content.innerHTML = avatarHtml;
        icon.style.display = 'none';
        content.style.display = 'flex';

        // If avatar is an image, remove gradient background
        if (currentUser.avatar) {
            content.style.background = 'transparent';
        } else {
            content.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        }
    }

    /**
     * Handle click events
     */
    function handleClick() {
        // Emit custom event
        const event = new CustomEvent('avatar-clicked', {
            detail: { user: currentUser },
            bubbles: true
        });
        container.dispatchEvent(event);

        // Toggle target element if specified
        if (targetId) {
            const target = document.getElementById(targetId);
            if (target) {
                const isHidden = target.style.display === 'none' || !target.style.display;
                target.style.display = isHidden ? 'block' : 'none';
            }
        }

        // Execute custom onClick function if specified
        if (customOnClick && typeof window[customOnClick] === 'function') {
            window[customOnClick]();
        } else if (customOnClick) {
            try {
                // eslint-disable-next-line no-eval
                eval(customOnClick);
            } catch (e) {
                console.error('[AccountAvatar] Error executing onClick:', e);
            }
        }
    }

    /**
     * Listen for SESSION_CHANGED messages from IFRAME
     */
    window.addEventListener('message', (event) => {
        // Verify origin
        if (authUrl && event.origin !== authUrl) {
            return;
        }

        const message = event.data;
        if (!message || !message.type) return;

        // Handle SESSION_CHANGED event
        if (message.type === 'SESSION_CHANGED' && message.payload) {
            updateAvatar(message.payload);
        }
    });

    // Add click handler
    container.addEventListener('click', handleClick);

    // Check if accountSwitcher is already initialized and has session data
    if (window.accountSwitcher && window.accountSwitcher.getSessionData) {
        try {
            const sessionData = window.accountSwitcher.getSessionData();
            updateAvatar(sessionData);
        } catch (e) {
            console.log('[AccountAvatar] Waiting for session data from IFRAME...');
        }
    }

    console.log('[AccountAvatar] Initialized for container:', containerId);
})();
</script>
