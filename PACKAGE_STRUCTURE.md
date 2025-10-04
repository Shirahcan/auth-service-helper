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
│   ├── Commands/
│   │   └── InstallCommand.php                   # Installation command
│   ├── Http/
│   │   └── Controllers/
│   │       ├── AuthController.php               # Authentication controller
│   │       └── AccountSwitcherController.php    # Account switcher controller
│   ├── Middleware/
│   │   ├── Authenticate.php                     # Authentication middleware
│   │   ├── HasRoleMiddleware.php                # Role-based route protection
│   │   ├── TrustedServiceMiddleware.php         # Service trust validation
│   │   └── Concerns/
│   │       └── RespondsWithAuthErrors.php       # Shared auth response trait
│   ├── Services/
│   │   └── AuthServiceClient.php                # HTTP client for auth service
│   ├── Auth/
│   │   ├── SessionGuard.php                     # Custom auth guard
│   │   └── SessionUserProvider.php              # Custom user provider
│   ├── Models/
│   │   └── User.php                             # User model
│   ├── Query/
│   │   └── UserQueryBuilder.php                 # User query builder
│   ├── Collections/
│   │   └── UserCollection.php                   # User collection
│   ├── Helpers/
│   │   └── auth_helpers.php                     # Helper functions
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

### Commands
- **InstallCommand**: Artisan command for easy package setup
  - Publishes configuration files
  - Optionally publishes views
  - Configures auth guard in config/auth.php
  - Sets authservice as default guard (optional)
  - Displays helpful setup instructions

### Controllers
- **AuthController**: Handles login display, landing session generation, authentication callback, and logout
- **AccountSwitcherController**: Manages account switching, session sync, and multi-account operations

### Middleware
- **Authenticate**: Simple authentication guard requiring logged-in users
- **HasRoleMiddleware**: Advanced role-based access control with service-scoped roles and global admin support
- **TrustedServiceMiddleware**: Validates service-to-service trust relationships via auth service API
- **RespondsWithAuthErrors (Trait)**: Shared response handling for authentication failures across middleware

### Auth System
- **SessionGuard**: Custom Laravel auth guard for session-based authentication
- **SessionUserProvider**: Custom user provider for the authservice guard

### Models & Query Builder
- **User**: Full Authenticatable user model with role checking and API interactions
- **UserQueryBuilder**: Eloquent-like query builder for fetching users from auth service
- **UserCollection**: Extended collection with user-specific methods

### Services
- **AuthServiceClient**: HTTP client for interacting with auth service API
  - Generate landing sessions
  - Get landing status
  - Validate tokens
  - Logout users
  - Check service trust relationships
  - Query users with filters and pagination

### Helpers
- **auth_helpers.php**: Convenient helper functions
  - `authservice_user()` - Get authenticated user
  - `authservice_id()` - Get user ID
  - `authservice_check()` - Check if authenticated
  - `authservice_guest()` - Check if guest

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
- GET `/auth/session-accounts` - Get user's session accounts
- POST `/auth/switch-account` - Switch active account
- POST `/auth/create-add-account-session` - Create add account session
- DELETE `/auth/remove-account/{uuid}` - Remove account from session
- POST `/auth/sync-session` - Sync session from iframe
- POST `/auth/sync-token` - Sync token from iframe

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

# Quick install (recommended)
php artisan authservice:install --with-views --as-default

# Or manual install
php artisan vendor:publish --tag=authservice-config
```

### Install Command Options
```bash
# Basic installation (publishes config only)
php artisan authservice:install

# Install with views
php artisan authservice:install --with-views

# Install and configure auth guard
php artisan authservice:install --configure-guard

# Install and set as default guard
php artisan authservice:install --as-default
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
// Require authentication only
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('authservice.auth');

// Require specific role
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware('authservice.role:admin');

// Require one of multiple roles (OR logic)
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

### Using Auth System
```php
// Using the authservice guard
$user = auth('authservice')->user();
$id = auth('authservice')->id();

// Using helper functions
$user = authservice_user();
$id = authservice_id();
if (authservice_check()) {
    // User is authenticated
}

// Check roles
if ($user->hasRole('admin')) {
    // User is admin
}

// Query users
$admins = User::where('is_admin', true)->get();
$user = User::find('uuid-here');
$users = User::paginate(20);
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
✅ Custom Laravel auth guard and provider
✅ Eloquent-like user query builder
✅ Helper functions for quick access
✅ Artisan install command for easy setup
✅ GitHub-based installation
✅ Fully documented and tested

## Related Packages

- [auth-service-wrapper-laravel](https://github.com/Shirahcan/auth-service-wrapper-laravel) - Full-featured API wrapper

## License

MIT License
