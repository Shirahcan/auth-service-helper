<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Account Switcher Test</title>
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
        .status-warning {
            background: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body>
    <div class="test-container">
        <div class="header">
            <h1>Account Switcher Test Page</h1>
            <x-authservice-account-switcher />
        </div>

        @if ($user = auth('authservice')->user())

            <div style="margin-bottom: 20px; padding: 20px; background: #e0f2fe; border-radius: 8px; color: #0369a1;">
                Logged in as <strong>{{ $user->name }}</strong> ({{ $user->email }})
            </div>

        @endif

        <div class="content">
            <h2>Test Instructions</h2>
            <ol>
                <li><strong>Login Flow:</strong> If no user is logged in, click the account switcher to see login interface, then click "Sign In" to be redirected to login landing page</li>
                <li><strong>After Login:</strong> You should see your account in the switcher with avatar/initials</li>
                <li><strong>Add Account:</strong> Click "Add another account" to generate landing page and add a second account</li>
                <li><strong>Switch Accounts:</strong> Click "Show X more accounts" to expand list, then click on an account to switch</li>
                <li><strong>Session Status:</strong> Check colored borders on avatars indicating session status (green=active, orange=dormant, red=expired/suspended)</li>
                <li><strong>Remove Account:</strong> Click "Sign out" button next to an account in the expanded list</li>
                <li><strong>Sign Out All:</strong> Click "Sign out of all accounts" to clear all sessions and return to login state</li>
            </ol>

            <h3>Expected Behavior</h3>
            <ul>
                <li>Dropdown should open/close smoothly on button click</li>
                <li>Accounts list should expand/collapse when clicking the section header</li>
                <li>All AJAX requests should go through package routes (check Network tab)</li>
                <li>Page should reload after account switch or removal</li>
                <li>Login and add-account should redirect to auth service landing pages</li>
                <li>No localStorage usage (check Application tab)</li>
                <li>Session data stored server-side in Laravel sessions</li>
            </ul>

            <h3>Chrome DevTools Checklist</h3>
            <ol>
                <li><strong>Network Tab:</strong>
                    <ul>
                        <li>Verify all requests go to <code>/auth/*</code> routes (not directly to auth-service)</li>
                        <li>Check request/response payloads for session-accounts, switch-account, etc.</li>
                        <li>Verify CSRF tokens are included in POST/DELETE requests</li>
                    </ul>
                </li>
                <li><strong>Application Tab:</strong>
                    <ul>
                        <li>Check Sessions → Confirm Laravel session cookie exists</li>
                        <li>Verify no auth tokens in localStorage</li>
                        <li>Session data should be server-side only</li>
                    </ul>
                </li>
                <li><strong>Console Tab:</strong>
                    <ul>
                        <li>Check for JavaScript errors</li>
                        <li>Verify clean execution of all interactions</li>
                    </ul>
                </li>
                <li><strong>Elements Tab:</strong>
                    <ul>
                        <li>Inspect account switcher component structure</li>
                        <li>Verify CSS is scoped and inline</li>
                        <li>Check session status classes on avatars</li>
                    </ul>
                </li>
            </ol>

            <h3>Current Session Data</h3>
            <pre>{{ json_encode(session()->all(), JSON_PRETTY_PRINT) }}</pre>

            <h3>Configuration</h3>
            <pre>Auth Service URL: {{ config('authservice.auth_service_base_url') }}
Service Slug: {{ config('authservice.auth_service_slug') }}
Session Driver: {{ config('session.driver') }}</pre>
        </div>
    </div>
</body>
</html>
