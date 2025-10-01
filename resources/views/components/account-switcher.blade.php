<div id="{{ $id }}" class="account-switcher-container" data-roles="{{ $roles }}">
    <style>
        .account-switcher-container {
            --primary-color: #1a73e8;
            --text-primary: #202124;
            --text-secondary: #5f6368;
            --border-light: #dadce0;
            --hover-bg: #f8f9fa;
            --surface: #ffffff;
            --shadow: 0 2px 10px rgba(0,0,0,0.2);

            display: inline-block;
            position: relative;
            font-family: 'Google Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }

        .account-switcher-container .current-user-button {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--surface);
            border: 1px solid var(--border-light);
            border-radius: 50px;
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.15s ease;
            outline: none;
            width: 50px;
            height: 50px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        }

        .account-switcher-container .current-user-button:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .account-switcher-container .current-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 500;
            flex-shrink: 0;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        }

        .account-switcher-container .current-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: inherit;
        }

        .account-switcher-container .dropdown-panel {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            width: 320px;
            background: var(--surface);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            box-shadow: var(--shadow);
            z-index: 1000;
            overflow: hidden;
        }

        .account-switcher-container .dropdown-panel.open {
            display: block;
        }

        .account-switcher-container .panel-header {
            padding: 24px 24px 16px;
            text-align: center;
            border-bottom: 1px solid var(--border-light);
            position: relative;
        }

        .account-switcher-container .close-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 32px;
            height: 32px;
            border: none;
            background: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            transition: background-color 0.15s ease;
        }

        .account-switcher-container .close-btn:hover {
            background: var(--hover-bg);
        }

        .account-switcher-container .main-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 500;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            margin: 0 auto 12px;
        }

        .account-switcher-container .main-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: inherit;
        }

        .account-switcher-container .greeting {
            font-size: 16px;
            font-weight: 400;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .account-switcher-container .current-email {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 16px;
        }

        .account-switcher-container .manage-account-btn {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            border: 1px solid var(--border-light);
            border-radius: 20px;
            background: var(--surface);
            color: var(--primary-color);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.15s ease;
            cursor: pointer;
        }

        .account-switcher-container .manage-account-btn:hover {
            background: var(--hover-bg);
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        }

        .account-switcher-container .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px 8px;
            cursor: pointer;
            user-select: none;
        }

        .account-switcher-container .section-title {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .account-switcher-container .expand-icon {
            width: 20px;
            height: 20px;
            transition: transform 0.2s ease;
            color: var(--text-secondary);
        }

        .account-switcher-container .section-header.expanded .expand-icon {
            transform: rotate(180deg);
        }

        .account-switcher-container .accounts-list {
            display: none;
            padding: 0 12px 12px;
            max-height: 300px;
            overflow-y: auto;
        }

        .account-switcher-container .accounts-list.expanded {
            display: block;
        }

        .account-switcher-container .account-item-wrapper {
            display: flex;
            flex-direction: column;
            width: 100%;
            border-bottom: 1px solid var(--border-light);
            padding-bottom: 8px;
            margin-bottom: 8px;
        }

        .account-switcher-container .account-item-wrapper:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .account-switcher-container .account-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.15s ease;
            position: relative;
        }

        .account-switcher-container .account-item:hover {
            background: var(--hover-bg);
        }

        .account-switcher-container .account-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 500;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            flex-shrink: 0;
        }

        .account-switcher-container .account-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: inherit;
        }

        .account-switcher-container .account-avatar.status-active {
            border: 3px solid #16a34a;
        }

        .account-switcher-container .account-avatar.status-dormant {
            border: 3px solid #b45309;
        }

        .account-switcher-container .account-avatar.status-expired {
            border: 3px solid #dc2626;
        }

        .account-switcher-container .account-avatar.status-suspended {
            border: 3px solid #dc2626;
        }

        .account-switcher-container .account-info {
            flex: 1;
            min-width: 0;
        }

        .account-switcher-container .account-name {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .account-switcher-container .account-email {
            font-size: 13px;
            color: var(--text-secondary);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .account-switcher-container .account-actions {
            margin-top: 4px;
            padding-left: 64px;
        }

        .account-switcher-container .account-signout-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.15s ease;
            color: var(--text-primary);
            font-size: 14px;
            font-weight: 500;
            background: white;
            border: 1px solid #dadce0;
            min-width: 80px;
            height: 36px;
        }

        .account-switcher-container .account-signout-btn:hover {
            background: #f8f9fa;
        }

        .account-switcher-container .panel-footer {
            border-top: 1px solid var(--border-light);
            padding: 12px;
        }

        .account-switcher-container .footer-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.15s ease;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 14px;
            background: none;
            border: none;
            width: 100%;
            text-align: left;
        }

        .account-switcher-container .footer-item:hover {
            background: var(--hover-bg);
        }

        .account-switcher-container .footer-icon {
            width: 20px;
            height: 20px;
            color: var(--text-secondary);
        }

        /* Login Interface Styles */
        .account-switcher-container .login-interface {
            padding: 24px;
        }

        .account-switcher-container .login-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .account-switcher-container .login-icon {
            margin-bottom: 16px;
        }

        .account-switcher-container .login-header h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .account-switcher-container .login-header p {
            margin: 0;
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        .account-switcher-container .login-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px 24px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .account-switcher-container .login-btn:hover:not(:disabled) {
            background: #1557b0;
        }

        .account-switcher-container .login-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
    </style>

    <button class="current-user-button" aria-expanded="false" aria-haspopup="true" type="button">
        <div class="current-avatar">
            @if($isLoggedIn && $activeAccount)
                @php
                    $activeAccountData = collect($accounts)->firstWhere('id', $activeAccount['uuid']);
                    $avatar = $activeAccountData['avatar'] ?? null;
                    $name = $activeAccountData['name'] ?? 'User';
                    $initials = collect(explode(' ', $name))->map(fn($n) => substr($n, 0, 1))->take(2)->join('');
                @endphp
                @if($avatar)
                    @if(str_starts_with($avatar, 'http'))
                        <img src="{{ $avatar }}" alt="{{ $name }}">
                    @else
                        <img src="/storage/{{ $avatar }}" alt="{{ $name }}">
                    @endif
                @else
                    {{ strtoupper($initials) }}
                @endif
            @else
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                    <polyline points="10,17 15,12 10,7"></polyline>
                    <line x1="15" y1="12" x2="3" y2="12"></line>
                </svg>
            @endif
        </div>
    </button>

    <div class="dropdown-panel" role="dialog" aria-label="Account switcher">
        @if($isLoggedIn)
            <button class="close-btn" aria-label="Close" type="button">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>

            <div class="panel-header">
                @php
                    $activeAccountData = collect($accounts)->firstWhere('id', $activeAccount['uuid']);
                    $avatar = $activeAccountData['avatar'] ?? null;
                    $name = $activeAccountData['name'] ?? 'User';
                    $email = $activeAccountData['email'] ?? '';
                    $firstName = explode(' ', $name)[0];
                    $initials = collect(explode(' ', $name))->map(fn($n) => substr($n, 0, 1))->take(2)->join('');
                @endphp
                <div class="main-avatar">
                    @if($avatar)
                        @if(str_starts_with($avatar, 'http'))
                            <img src="{{ $avatar }}" alt="{{ $name }}">
                        @else
                            <img src="/storage/{{ $avatar }}" alt="{{ $name }}">
                        @endif
                    @else
                        {{ strtoupper($initials) }}
                    @endif
                </div>
                <div class="greeting">Hi, {{ $firstName }}!</div>
                <div class="current-email">{{ $email }}</div>
                <button class="manage-account-btn" type="button">Manage your account</button>
            </div>

            <div class="accounts-section">
                @php
                    $otherAccounts = collect($accounts)->filter(fn($acc) => !($acc['is_active'] ?? false))->values();
                @endphp
                @if($otherAccounts->count() > 0)
                    <div class="section-header" id="other-accounts-header">
                        <span class="section-title">Show {{ $otherAccounts->count() }} more account{{ $otherAccounts->count() > 1 ? 's' : '' }}</span>
                        <svg class="expand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6,9 12,15 18,9"></polyline>
                        </svg>
                    </div>
                    <div class="accounts-list" id="other-accounts-list">
                        @foreach($otherAccounts as $account)
                            @php
                                $accountAvatar = $account['avatar'] ?? null;
                                $accountName = $account['name'] ?? 'User';
                                $accountEmail = $account['email'] ?? '';
                                $accountInitials = collect(explode(' ', $accountName))->map(fn($n) => substr($n, 0, 1))->take(2)->join('');
                                $sessionStatus = $account['session_status'] ?? null;
                                $statusClass = $sessionStatus ? 'status-' . $sessionStatus : '';
                            @endphp
                            <div class="account-item-wrapper">
                                <div class="account-item" data-uuid="{{ $account['id'] }}">
                                    <div class="account-avatar {{ $statusClass }}">
                                        @if($accountAvatar)
                                            @if(str_starts_with($accountAvatar, 'http'))
                                                <img src="{{ $accountAvatar }}" alt="{{ $accountName }}">
                                            @else
                                                <img src="/storage/{{ $accountAvatar }}" alt="{{ $accountName }}">
                                            @endif
                                        @else
                                            {{ strtoupper($accountInitials) }}
                                        @endif
                                    </div>
                                    <div class="account-info">
                                        <div class="account-name">{{ $accountName }}</div>
                                        <div class="account-email">{{ $accountEmail }}</div>
                                    </div>
                                </div>
                                <div class="account-actions">
                                    <button class="account-signout-btn" data-account-uuid="{{ $account['id'] }}" type="button">
                                        Sign out
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="panel-footer">
                <button class="footer-item" id="add-account-btn" type="button">
                    <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    Add another account
                </button>
                <button class="footer-item" id="sign-out-all-btn" type="button">
                    <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M16 17l5-5-5-5M21 12H9M10 3H5a2 2 0 00-2 2v14a2 2 0 002 2h5"></path>
                    </svg>
                    Sign out of all accounts
                </button>
            </div>
        @else
            <button class="close-btn" aria-label="Close" type="button">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>

            <div class="login-interface">
                <div class="login-header">
                    <div class="login-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--primary-color)" stroke-width="2">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                            <polyline points="10,17 15,12 10,7"></polyline>
                            <line x1="15" y1="12" x2="3" y2="12"></line>
                        </svg>
                    </div>
                    <h3>Sign in to your account</h3>
                    <p>Access your dashboard and manage your services</p>
                </div>

                <div class="login-actions">
                    <button class="login-btn" id="loginBtn" type="button">
                        <span class="login-btn-text">Sign In</span>
                    </button>
                </div>
            </div>
        @endif
    </div>

    <script>
    (function() {
        const container = document.getElementById('{{ $id }}');
        if (!container) return;

        const switcher = {
            isOpen: false,
            refreshInterval: null,
            roles: container.dataset.roles || null,

            init() {
                this.bindEvents();
                this.startAutoRefresh();
            },

            bindEvents() {
                // Toggle dropdown
                const button = container.querySelector('.current-user-button');
                button?.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.toggleDropdown();
                });

                // Close button
                const closeBtn = container.querySelector('.close-btn');
                closeBtn?.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.closeDropdown();
                });

                // Toggle accounts section
                const sectionHeader = container.querySelector('#other-accounts-header');
                sectionHeader?.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.toggleAccountsSection();
                });

                // Account items
                const accountItems = container.querySelectorAll('.account-item');
                accountItems.forEach(item => {
                    item.addEventListener('click', (e) => {
                        const uuid = e.currentTarget.dataset.uuid;
                        if (uuid) {
                            this.switchAccount(uuid);
                        }
                    });
                });

                // Sign out buttons
                const signOutBtns = container.querySelectorAll('.account-signout-btn');
                signOutBtns.forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const uuid = e.currentTarget.dataset.accountUuid;
                        const accountName = e.currentTarget.closest('.account-item-wrapper')?.querySelector('.account-name')?.textContent || 'this account';
                        if (uuid && confirm(`Sign out ${accountName}?`)) {
                            this.removeAccount(uuid);
                        }
                    });
                });

                // Add account button
                const addAccountBtn = container.querySelector('#add-account-btn');
                addAccountBtn?.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.addAccount();
                });

                // Sign out all button
                const signOutAllBtn = container.querySelector('#sign-out-all-btn');
                signOutAllBtn?.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (confirm('Are you sure you want to sign out of all accounts?')) {
                        this.signOutAll();
                    }
                });

                // Login button
                const loginBtn = container.querySelector('#loginBtn');
                loginBtn?.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.initiateLogin();
                });

                // Close on outside click
                document.addEventListener('click', (e) => {
                    if (!container.contains(e.target) && this.isOpen) {
                        this.closeDropdown();
                    }
                });

                // Escape key
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.isOpen) {
                        this.closeDropdown();
                    }
                });
            },

            toggleDropdown() {
                this.isOpen = !this.isOpen;
                const panel = container.querySelector('.dropdown-panel');
                const button = container.querySelector('.current-user-button');

                if (this.isOpen) {
                    panel.classList.add('open');
                    button.setAttribute('aria-expanded', 'true');
                } else {
                    panel.classList.remove('open');
                    button.setAttribute('aria-expanded', 'false');
                }
            },

            closeDropdown() {
                this.isOpen = false;
                const panel = container.querySelector('.dropdown-panel');
                const button = container.querySelector('.current-user-button');
                panel.classList.remove('open');
                button.setAttribute('aria-expanded', 'false');
            },

            toggleAccountsSection() {
                const header = container.querySelector('#other-accounts-header');
                const list = container.querySelector('#other-accounts-list');

                if (list.classList.contains('expanded')) {
                    list.classList.remove('expanded');
                    header.classList.remove('expanded');
                } else {
                    list.classList.add('expanded');
                    header.classList.add('expanded');
                }
            },

            async switchAccount(userUuid) {
                try {
                    const response = await fetch('/auth/switch-account', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        },
                        body: JSON.stringify({ user_uuid: userUuid })
                    });

                    const data = await response.json();

                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.message || 'Failed to switch account');
                    }
                } catch (error) {
                    console.error('Error switching account:', error);
                    alert('Failed to switch account');
                }
            },

            async addAccount() {
                try {
                    const callbackUrl = window.location.origin + '/auth/callback' +
                        '?action=add-account&return_url=' + encodeURIComponent(window.location.href);
                    const requestBody = {
                        callback_url: callbackUrl
                    };

                    if (this.roles) {
                        requestBody.roles = this.roles;
                    }

                    const response = await fetch('/auth/create-add-account-session', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        },
                        body: JSON.stringify(requestBody)
                    });

                    const data = await response.json();

                    if (data.success && data.data?.landing_url) {
                        window.location.href = data.data.landing_url;
                    } else {
                        alert(data.message || 'Failed to create add account session');
                    }
                } catch (error) {
                    console.error('Error adding account:', error);
                    alert('Failed to add account');
                }
            },

            async removeAccount(uuid) {
                try {
                    const response = await fetch(`/auth/remove-account/${uuid}`, {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        }
                    });

                    const data = await response.json();

                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.message || 'Failed to remove account');
                    }
                } catch (error) {
                    console.error('Error removing account:', error);
                    alert('Failed to remove account');
                }
            },

            async signOutAll() {
                try {
                    const response = await fetch('/auth/logout', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        }
                    });

                    window.location.href = '/auth/login';
                } catch (error) {
                    console.error('Error signing out:', error);
                    window.location.href = '/auth/login';
                }
            },

            async initiateLogin() {
                try {
                    const loginBtn = container.querySelector('#loginBtn');
                    loginBtn.disabled = true;

                    const callbackUrl = window.location.origin + '/auth/callback';
                    const requestBody = {
                        action: 'login',
                        callback_url: callbackUrl
                    };

                    if (this.roles) {
                        requestBody.roles = this.roles.split(' ').filter(r => r.length > 0);
                    }

                    const response = await fetch('/auth/generate', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        },
                        body: JSON.stringify(requestBody)
                    });

                    const data = await response.json();

                    if (data.success && data.data?.auth_url) {
                        window.location.href = data.data.auth_url;
                    } else {
                        alert(data.message || 'Failed to create login session');
                        loginBtn.disabled = false;
                    }
                } catch (error) {
                    console.error('Error initiating login:', error);
                    alert('Failed to initiate login');
                    const loginBtn = container.querySelector('#loginBtn');
                    loginBtn.disabled = false;
                }
            },

            startAutoRefresh() {
                // Refresh every 30 seconds when dropdown is closed
                this.refreshInterval = setInterval(() => {
                    if (!this.isOpen) {
                        // Just reload the page to get fresh data
                        // In a real implementation, you might want to fetch via AJAX and update the DOM
                    }
                }, 30000);
            },

            stopAutoRefresh() {
                if (this.refreshInterval) {
                    clearInterval(this.refreshInterval);
                    this.refreshInterval = null;
                }
            }
        };

        switcher.init();
    })();
    </script>
</div>
