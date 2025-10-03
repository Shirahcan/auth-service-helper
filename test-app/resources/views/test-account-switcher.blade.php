<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Iframe Account Switcher Test</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            padding: 40px;
            background: #f3f4f6;
            margin: 0;
        }
        .test-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .content {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 {
            margin: 0;
            font-size: 24px;
            color: #111827;
        }
        h2 {
            margin-top: 0;
            color: #374151;
        }
        h3 {
            color: #4b5563;
            margin-top: 32px;
        }
        ol, ul {
            line-height: 1.8;
            color: #6b7280;
        }
        pre {
            background: #f9fafb;
            padding: 16px;
            border-radius: 4px;
            overflow-x: auto;
            border: 1px solid #e5e7eb;
        }
        .status-indicator {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin-left: 8px;
        }
        .status-success {
            background: #d1fae5;
            color: #065f46;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            background: #dbeafe;
            color: #1e40af;
        }
    </style>
</head>
<body>
    <div class="test-container">
        <div class="header">
            <div>
                <h1>Iframe Account Switcher Test <span class="badge">New Implementation</span></h1>
                <p style="margin: 4px 0 0 0; font-size: 14px; color: #6b7280;">
                    Secure iframe-based widget with comprehensive session syncing
                </p>
            </div>
            <x-authservice-account-switcher />
        </div>

        @if ($user = auth('authservice')->user())
            <div style="margin-bottom: 20px; padding: 20px; background: #e0f2fe; border-radius: 8px; color: #0369a1;">
                Logged in as <strong>{{ $user->name }}</strong> ({{ $user->email }})
            </div>
        @endif

        <div class="content">
            <h2>🎯 Test Instructions</h2>
            <ol>
                <li><strong>Initial Load:</strong> The iframe account switcher should automatically load and display current session state</li>
                <li><strong>Login Flow (if not logged in):</strong> Click the switcher button, then "Sign In" to be redirected to auth-service login page</li>
                <li><strong>After Login:</strong> You should see your account in the switcher with avatar/initials and Material Design UI</li>
                <li><strong>Add Account:</strong> Click "Add another account" to generate landing page and add a second account</li>
                <li><strong>Interactive Dialogs:</strong> When signing out, you should see a Material Design confirmation dialog (not browser alert)</li>
                <li><strong>Switch Accounts:</strong> Click "Show X more accounts" to expand list, then click on an account to switch (page reloads)</li>
                <li><strong>Session Sync:</strong> Session state should automatically sync to Laravel backend on changes</li>
                <li><strong>Auto-Resize:</strong> The iframe should automatically adjust its height based on content</li>
                <li><strong>Sign Out All:</strong> Click "Sign out of all accounts" with confirmation dialog</li>
            </ol>

            <h3>🔒 Security Features (Iframe Implementation)</h3>
            <ul>
                <li><strong>JWT Tokens:</strong> Cryptographically signed, time-limited (5 min), one-time use</li>
                <li><strong>Origin Validation:</strong> Both server-side CSP headers and client-side postMessage validation</li>
                <li><strong>PostMessage Protocol:</strong> Secure cross-origin communication with request/response correlation</li>
                <li><strong>Rate Limiting:</strong> 60 requests/minute for token generation</li>
                <li><strong>Cookie-Based Flow:</strong> Secure cookies for iframe login flows (excluded from encryption middleware)</li>
                <li><strong>No Direct Token Exposure:</strong> API key passed via postMessage, not in URL params</li>
            </ul>

            <h3>✨ New Features vs. Custom Implementation</h3>
            <ul>
                <li><strong>Material Design Dialogs:</strong> Interactive confirm/prompt/alert dialogs with animations and focus management</li>
                <li><strong>Auto-Resize:</strong> Iframe automatically adjusts height based on content (with configurable min/max)</li>
                <li><strong>SPA Support:</strong> Dynamic page URL updates for correct callback URLs</li>
                <li><strong>Session Synchronization:</strong> Bidirectional sync between iframe and Laravel session</li>
                <li><strong>Production-Ready:</strong> 386 passing tests in auth-service implementation</li>
                <li><strong>Maintained by Auth-Service:</strong> Updates and bug fixes happen centrally</li>
            </ul>

            <h3>🔍 Chrome DevTools Checklist</h3>
            <ol>
                <li><strong>Network Tab:</strong>
                    <ul>
                        <li>Verify POST to <code>/api/v1/widgets/embed/generate-token</code> returns JWT</li>
                        <li>Check iframe loads from <code>/widgets/embed?token=...</code></li>
                        <li>Monitor postMessage events in Console (see below)</li>
                        <li>Verify sync requests to <code>/auth/sync-session</code> and <code>/auth/sync-token</code></li>
                    </ul>
                </li>
                <li><strong>Console Tab:</strong>
                    <ul>
                        <li>Look for <code>[AccountSwitcher]</code> initialization messages</li>
                        <li>Monitor session change events and token refresh events</li>
                        <li>Check for errors in postMessage communication</li>
                    </ul>
                </li>
                <li><strong>Application Tab:</strong>
                    <ul>
                        <li>Verify Laravel session cookie exists</li>
                        <li>Check iframe uses <code>localStorage</code> for auth tokens (iframe context only)</li>
                        <li>Verify <code>iframe_auth_token</code> cookie appears after login flow (5 min expiry)</li>
                    </ul>
                </li>
                <li><strong>Elements Tab:</strong>
                    <ul>
                        <li>Inspect iframe element and verify <code>src</code> attribute contains JWT</li>
                        <li>Check iframe height adjusts automatically when content changes</li>
                        <li>Inspect dialog elements when shown (Material Design styled)</li>
                    </ul>
                </li>
            </ol>

            <h3>📡 PostMessage Protocol</h3>
            <p>The iframe communicates with the host via standardized messages:</p>
            <ul>
                <li><code>READY</code> → <code>HANDSHAKE</code> → <code>HANDSHAKE_ACK</code> (connection establishment)</li>
                <li><code>SESSION_CHANGED</code> (iframe → host): Session state updated</li>
                <li><code>ACCOUNT_SWITCHED</code> (iframe → host): User switched accounts</li>
                <li><code>TOKEN_REFRESHED</code> (iframe → host): Auth token renewed</li>
                <li><code>SIZE_CHANGED</code> (iframe → host): Content height changed</li>
                <li><code>ADD_ACCOUNT</code> (iframe → host): Redirect to add account flow</li>
                <li><code>CONFIRM_REQUEST</code> / <code>PROMPT_REQUEST</code> / <code>ALERT_REQUEST</code> (interactive dialogs)</li>
                <li><code>SET_PAGE_URL</code> (host → iframe): Update current page URL for callbacks</li>
            </ul>

            <h3>⚙️ Component Configuration</h3>
            <p>The component supports various configuration options:</p>
            <pre>{{ '<x-authservice-account-switcher
    :auto-resize="true"
    :min-height="200"
    :max-height="600"
    :dialogs-enabled="true"
    :reload-on-switch="true"
    :spa-support="false"
/>' }}</pre>

            <h3>📊 Current Session Data</h3>
            <pre>{{ json_encode(session()->all(), JSON_PRETTY_PRINT) }}</pre>

            <h3>🔧 Configuration</h3>
            <pre>Auth Service URL: {{ config('authservice.auth_service_base_url') }}
Service Slug: {{ config('authservice.auth_service_slug') }}
Session Driver: {{ config('session.driver') }}
Implementation: Iframe-based widget (migrated 2025-10-03)</pre>
        </div>
    </div>
</body>
</html>
