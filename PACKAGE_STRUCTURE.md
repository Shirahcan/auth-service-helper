# Auth Service Helper Package Structure

## Overview
This is a lightweight Laravel 12 package for integrating with the Authentication Microservice. It provides web authentication flows, middleware, and UI components.

## Directory Structure

```
auth-service-helper/
├── config/
│   └── authservice.php                          # Package configuration
├── resources/
│   └── views/
│       ├── auth/
│       │   ├── login.blade.php                  # Login page view
│       │   └── redirect-with-token.blade.php    # Token storage & redirect view
│       └── components/
│           ├── account-switcher-loader.blade.php # Script loader (legacy)
│           ├── account-switcher.blade.php        # Iframe account switcher
│           └── account-avatar.blade.php          # Account avatar component
├── src/
│   ├── AuthServiceHelperServiceProvider.php    # Laravel service provider
│   ├── Http/
│   │   └── Controllers/
│   │       └── AuthController.php               # Authentication controller
│   ├── Middleware/
│   │   ├── HasRoleMiddleware.php                # Role-based route protection
│   │   └── TrustedServiceMiddleware.php         # Service trust validation
│   ├── Services/
│   │   └── AuthServiceClient.php                # HTTP client for auth service
│   └── View/
│       └── Components/
│           ├── AccountSwitcher.php              # Iframe account switcher
│           ├── AccountSwitcherLoader.php        # Loader (legacy)
│           └── AccountAvatar.php                # Account avatar component
├── tests/
│   ├── Feature/
│   │   └── RoutesTest.php                       # Route registration tests
│   ├── Unit/
│   │   └── ConfigTest.php                       # Configuration tests
│   └── TestCase.php                             # Base test case
├── .gitignore                                   # Git ignore file
├── composer.json                                # Composer configuration
├── phpunit.xml                                  # PHPUnit configuration
├── README.md                                    # Package documentation
└── PACKAGE_STRUCTURE.md                         # This file
```

## Components

### Configuration
- **config/authservice.php**: All package settings including auth service URL, API key, service slug, timeout, optional login roles, callback URL, and redirect destination

### Controllers
- **AuthController**: Handles login display, landing session generation, authentication callback, and logout

### Middleware
- **TrustedServiceMiddleware**: Validates service-to-service trust relationships via auth service API
- **HasRoleMiddleware**: Protects routes by checking user roles (supports multiple roles with OR logic)

### Services
- **AuthServiceClient**: HTTP client for interacting with auth service API
  - Generate landing sessions
  - Get landing status
  - Validate tokens
  - Logout users
  - Check service trust relationships

### Blade Components
- **AccountSwitcher**: Secure iframe-based account switcher with session synchronization and auto-reload
- **AccountAvatar**: Compact clickable avatar for NAV bars with session sync
- **AccountSwitcherLoader**: Legacy script loader (deprecated)

### Views
- **login.blade.php**: Modern, responsive login page with role-based filtering support
- **redirect-with-token.blade.php**: Stores token in localStorage/sessionStorage and redirects

### Routes
Auto-registered routes:
- GET `/auth/login` - Display login page
- POST `/auth/generate` - Generate authentication landing session
- GET `/auth/callback` - Handle authentication callback
- POST `/auth/logout` - Logout user

## Installation

Add to composer.json:
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Shirahcan/auth-service-helper.git"
        }
    ],
    "require": {
        "shirahcan/auth-service-helper": "dev-main"
    }
}
```

Then run:
```bash
composer install
php artisan vendor:publish --tag=authservice-config
```

## Environment Variables

Required:
```env
AUTH_SERVICE_BASE_URL=http://localhost:8000
AUTH_SERVICE_API_KEY=your_service_api_key
AUTH_SERVICE_SLUG=your-service-slug
```

Optional:
```env
AUTH_SERVICE_TIMEOUT=30
AUTH_SERVICE_LOGIN_ROLES=admin,manager
AUTH_SERVICE_CALLBACK_URL=/auth/callback
AUTH_SERVICE_REDIRECT_AFTER_LOGIN=/dashboard
```

## Usage Examples

### Protecting Routes
```php
// Single role
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware('authservice.role:admin');

// Multiple roles (OR logic)
Route::get('/management', [Controller::class, 'index'])
    ->middleware('authservice.role:admin,manager');

// Service trust validation
Route::post('/api/action', [ServiceController::class, 'action'])
    ->middleware('authservice.trusted:target-service');
```

### Using Blade Components
```blade
<!-- Account switcher (iframe-based) -->
<x-authservice-account-switcher />

<!-- Account avatar in NAV -->
<x-authservice-account-avatar :size="36" target-id="account-dropdown" />

<!-- Hidden dropdown with switcher -->
<div id="account-dropdown" style="display: none;">
    <x-authservice-account-switcher />
</div>
```

### Accessing Session Data
```php
$token = session('auth_token');
$user = session('auth_user');
$loginTime = session('login_time');
```

## Dependencies

- PHP 8.2+
- Laravel 12.0+
- GuzzleHTTP 7.0+
- Illuminate packages (support, http, routing, view, session)

## Features

✅ Drop-in login page with modern UI
✅ Role-based access control middleware
✅ Service trust validation middleware
✅ Iframe-based account switcher with session sync
✅ Account avatar component for NAV bars
✅ Token management (localStorage + sessionStorage)
✅ Session lifecycle management
✅ GitHub-based installation
✅ Fully documented and tested

## Related Packages

- [auth-service-wrapper-laravel](https://github.com/Shirahcan/auth-service-wrapper-laravel) - Full-featured API wrapper

## License

MIT License
