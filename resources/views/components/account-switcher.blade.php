{{--
    Iframe-based Account Switcher Component

    This component embeds the secure iframe-based account switcher widget from the auth-service.
    It provides comprehensive session synchronization between the iframe widget and the Laravel application.

    Features:
    - Secure JWT-based token authentication
    - PostMessage protocol for cross-origin communication
    - Automatic session synchronization
    - Material Design interactive dialogs
    - Auto-resize support
    - Multi-account session management

    Previous Implementation: Custom HTML/CSS/JS (migrated to iframe on 2025-10-03)
--}}

<div id="{{ $containerId }}" style="width: 100%; min-height: {{ $minHeight }}px;"></div>

<script src="{{ $authUrl }}/js/account-switcher-client.js"></script>

<script>
(function() {
    'use strict';

    // Configuration
    const config = {
        apiKey: '{{ $apiKey }}',
        serviceSlug: '{{ $serviceSlug }}',
        authBaseUrl: '{{ $authUrl }}',
        container: '#{{ $containerId }}',
        roles: {!! $roles ? json_encode($roles) : 'null' !!},
        autoResize: {{ $autoResize ? 'true' : 'false' }},
        minHeight: {{ $minHeight }},
        maxHeight: {{ $maxHeight ? $maxHeight : 'null' }},

        // Session synchronization callbacks
        onSessionChange: async (session) => {
            console.log('[AccountSwitcher] Session changed:', session);

            // Sync session state to Laravel backend
            try {
                const response = await fetch('{{ route('auth.sync-session') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        is_authenticated: session.isAuthenticated,
                        current_user: session.currentUser,
                        accounts: session.accounts
                    })
                });

                const result = await response.json();

                // Reload page if account changed (new account added or switched)
                if (result.should_reload) {
                    console.log('[AccountSwitcher] Account change detected, reloading page...');
                    window.location.reload();
                }
            } catch (error) {
                console.error('[AccountSwitcher] Failed to sync session:', error);
            }
        },

        onAccountChange: (account) => {
            console.log('[AccountSwitcher] Account switched to:', account);

            // Reload the page to update Laravel auth state
            @if($reloadOnSwitch)
            window.location.reload();
            @else
            // Custom callback can be handled here if needed
            if (typeof window.onAccountSwitchCallback === 'function') {
                window.onAccountSwitchCallback(account);
            }
            @endif
        },

        onError: (error) => {
            console.error('[AccountSwitcher] Error:', error);
        },

        onResize: (height) => {
            console.log('[AccountSwitcher] Height changed:', height);
        },

        // Dialog configuration
        dialogs: {
            enabled: {{ $dialogsEnabled ? 'true' : 'false' }},
            closeOnOverlayClick: true,
            closeOnEscape: true
        }
    };

    // Initialize account switcher when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAccountSwitcher);
    } else {
        initAccountSwitcher();
    }

    async function initAccountSwitcher() {
        try {
            // Check if AccountSwitcherClient is available
            if (typeof window.AccountSwitcherClient === 'undefined') {
                console.error('[AccountSwitcher] AccountSwitcherClient not found. Make sure the script is loaded.');
                return;
            }

            // Create and initialize switcher
            const switcher = new window.AccountSwitcherClient(config);
            await switcher.init();

            // Store reference globally for SPA support
            window.accountSwitcher = switcher;

            console.log('[AccountSwitcher] Initialized successfully');

            // Listen for token refresh events via postMessage
            window.addEventListener('message', (event) => {
                if (event.origin !== config.authBaseUrl) return;

                const message = event.data;
                if (!message || !message.type) return;

                // Handle TOKEN_REFRESHED event
                if (message.type === 'TOKEN_REFRESHED' && message.payload?.token) {
                    syncTokenToBackend(message.payload.token);
                }
            });

        } catch (error) {
            console.error('[AccountSwitcher] Initialization failed:', error);
        }
    }

    // Sync refreshed token to Laravel backend
    async function syncTokenToBackend(token) {
        try {
            await fetch('{{ route('auth.sync-token') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ token })
            });
            console.log('[AccountSwitcher] Token synced to backend');
        } catch (error) {
            console.error('[AccountSwitcher] Failed to sync token:', error);
        }
    }

    // SPA Support: Update page URL when navigating
    // Usage: window.accountSwitcher.setPageUrl(window.location.href)
    @if($spaSupport)
    if (typeof window.navigation !== 'undefined') {
        window.navigation.addEventListener('navigate', (e) => {
            if (window.accountSwitcher && typeof window.accountSwitcher.setPageUrl === 'function') {
                // Use the destination URL
                window.accountSwitcher.setPageUrl(e.destination.url);
            }
        });
    }
    @endif
})();
</script>
