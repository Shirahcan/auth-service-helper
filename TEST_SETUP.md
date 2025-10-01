# Account Switcher Testing Setup

## Prerequisites
- Auth service running at http://localhost:8000
- Service API key: `sk_r6a6_pQLhjIGuHHGcM7rmAcXUl3scJLkcwwn3`
- Service slug: `shirah-documents-service`

## Setup Instructions

### 1. Install package in a Laravel project

If you don't have a test Laravel project, create one:
```bash
composer create-project laravel/laravel test-app
cd test-app
```

Then install this package locally:
```bash
composer config repositories.auth-service-helper path ../auth-service-helper
composer require shirahcan/auth-service-helper:@dev
```

### 2. Configure the package

Add to `.env`:
```
AUTH_SERVICE_BASE_URL=http://localhost:8000
AUTH_SERVICE_API_KEY=sk_r6a6_pQLhjIGuHHGcM7rmAcXUl3scJLkcwwn3
AUTH_SERVICE_SLUG=shirah-documents-service
```

Publish config:
```bash
php artisan vendor:publish --tag=authservice-config
```

### 3. Add CSRF token meta tag

In `resources/views/layouts/app.blade.php` or your main layout, add:
```html
<head>
    ...
    <meta name="csrf-token" content="{{ csrf_token() }}">
    ...
</head>
```

### 4. Create test route and view

Add to `routes/web.php`:
```php
Route::get('/test-account-switcher', function () {
    return view('test-account-switcher');
})->name('test.account-switcher');
```

Create `resources/views/test-account-switcher.blade.php`:
```blade
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
    </style>
</head>
<body>
    <div class="test-container">
        <div class="header">
            <h1>Account Switcher Test Page</h1>
            <x-authservice::account-switcher />
        </div>

        <div class="content">
            <h2>Test Instructions</h2>
            <ol>
                <li>If no user is logged in, click the account switcher to see login interface</li>
                <li>Click "Sign In" to be redirected to login landing page</li>
                <li>After login, you should see your account in the switcher</li>
                <li>Test adding another account</li>
                <li>Test switching between accounts</li>
                <li>Test removing an account</li>
                <li>Test sign out all</li>
            </ol>

            <h3>Current Session Data</h3>
            <pre>{{ json_encode(session()->all(), JSON_PRETTY_PRINT) }}</pre>
        </div>
    </div>
</body>
</html>
```

### 5. Start the server

```bash
php artisan serve --port=8100
```

### 6. Test in Browser

1. Open Chrome DevTools (F12)
2. Navigate to http://localhost:8100/test-account-switcher
3. Test all features:
   - Login flow
   - Account switching
   - Adding accounts
   - Removing accounts
   - Sign out all

### 7. Testing Scenarios

#### Scenario 1: No user logged in
- Should show login icon in account switcher button
- Clicking should show login interface
- "Sign In" button should create landing session and redirect

#### Scenario 2: Single account logged in
- Should show user avatar/initials
- Panel should show user info with "Manage account" button
- "Add another account" should create add-account landing session

#### Scenario 3: Multiple accounts
- Should show expandable accounts list
- Each account should have session status indicator
- Can switch between accounts
- Can remove individual accounts
- "Sign out all" should clear all accounts

#### Scenario 4: Session status indicators
Test with accounts in different session states:
- Active (green border)
- Dormant (orange border)
- Expired (red border)
- Suspended (red border with warning)

## Troubleshooting

### Routes not working
Ensure the package service provider is loaded:
```bash
php artisan route:list | grep auth
```

### Component not rendering
Clear cache:
```bash
php artisan view:clear
php artisan config:clear
```

### Session issues
Check session configuration in `config/session.php`

### CSRF token issues
Ensure meta tag is present and middleware is active
