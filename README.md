# Auth Service Helper for Laravel

A lightweight Laravel 12 package for easy integration with the Authentication Microservice. Provides web authentication flows, middleware for route protection, and UI components for seamless user experience.

## 🚀 Features

### 🔐 Web Authentication Flows
- **Drop-in Login Page**: Beautiful, responsive login interface with role-based filtering
- **Secure Token Management**: Automatic localStorage/sessionStorage token handling
- **Session Management**: Complete authentication session lifecycle management
- **Callback Handling**: Seamless integration with auth service callbacks

### 🛡️ Security Middleware
- **TrustedServiceMiddleware**: Validate service-to-service trust relationships
- **HasRoleMiddleware**: Protect routes with role-based access control (supports multiple roles with OR logic)

### 🎨 Blade Components
- **AccountSwitcherLoader**: Loads the account switcher JavaScript from auth service
- **AccountSwitcher**: Embeds the account switcher web component

### ⚡ Key Benefits
- **Lightweight**: Focused on web flows, not full API wrapping
- **Easy Integration**: Install and configure in minutes
- **Laravel 12 Ready**: Built specifically for Laravel 12
- **Responsive UI**: Modern, mobile-friendly authentication pages
- **Role-Based Access**: Optional role filtering for login pages
- **GitHub Installation**: Install directly from GitHub repository

## 📦 Installation

### Step 1: Install via Composer

Add the repository to your `composer.json`:

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
```

### Step 2: Publish Configuration

```bash
php artisan vendor:publish --tag=authservice-config
```

### Step 3: Configure Environment Variables

Add to your `.env` file:

```env
AUTH_SERVICE_BASE_URL=http://localhost:8000
AUTH_SERVICE_API_KEY=your_service_api_key_here
AUTH_SERVICE_SLUG=your-service-slug
AUTH_SERVICE_TIMEOUT=30

# Optional: Restrict login to specific roles (comma-separated)
AUTH_SERVICE_LOGIN_ROLES=admin,manager

# Optional: Customize URLs
AUTH_SERVICE_CALLBACK_URL=/auth/callback
AUTH_SERVICE_REDIRECT_AFTER_LOGIN=/dashboard
```

### Step 4 (Optional): Publish Views for Customization

```bash
php artisan vendor:publish --tag=authservice-views
```

Views will be published to `resources/views/vendor/authservice/`.

## 🎯 Usage

### Authentication Routes

The package automatically registers these routes:

| Method | URI | Description |
|--------|-----|-------------|
| GET | `/auth/login` | Display login page |
| POST | `/auth/generate` | Generate authentication landing session |
| GET | `/auth/callback` | Handle authentication callback |
| POST | `/auth/logout` | Logout user |

### Using the Login Page

Simply redirect users to the login route:

```php
return redirect()->route('auth.login');
```

Or create a link:

```html
<a href="{{ route('auth.login') }}">Login</a>
```

### Protecting Routes with Middleware

#### HasRoleMiddleware

Protect routes by requiring specific roles:

```php
use Illuminate\Support\Facades\Route;

// Require a single role
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware('authservice.role:admin');

// Require one of multiple roles (OR logic)
Route::get('/management', [ManagementController::class, 'index'])
    ->middleware('authservice.role:admin,manager,supervisor');

// Group routes with role protection
Route::middleware(['authservice.role:admin'])->group(function () {
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::get('/admin/settings', [AdminController::class, 'settings']);
});
```

The middleware checks for:
- Exact role match (e.g., `admin`)
- Service-scoped roles (e.g., `your-service:admin`)
- Global admin roles (`super-admin`, `admin`)

#### TrustedServiceMiddleware

Validate service-to-service trust relationships:

```php
// Validate against specific target service
Route::post('/api/cross-service', [ServiceController::class, 'crossService'])
    ->middleware('authservice.trusted:target-service-slug');

// Auto-detect target service from request
Route::post('/api/trusted-action', [ServiceController::class, 'action'])
    ->middleware('authservice.trusted');
```

Access trust data in your controller:

```php
public function crossService(Request $request)
{
    $callingService = $request->input('calling_service');
    $targetService = $request->input('target_service');
    $permissions = $request->input('trust_permissions');
    $callingServiceKey = $request->input('calling_service_key');

    // Your logic here
}
```

### Blade Components

#### Account Switcher Loader

Add to your layout's `<head>` section:

```html
<x-authservice-account-switcher-loader />

<!-- Or with custom auth URL -->
<x-authservice-account-switcher-loader
    :auth-url="env('AUTH_SERVICE_BASE_URL')"
    :auto-load="true" />
```

#### Account Switcher Component

Add to your layout where you want the account switcher to appear:

```html
<x-authservice-account-switcher />

<!-- Or with custom configuration -->
<x-authservice-account-switcher
    :auth-url="env('AUTH_SERVICE_BASE_URL')"
    :api-key="env('AUTH_SERVICE_API_KEY')"
    roles="admin,manager"
    id="my-account-switcher" />
```

### Session Management

Access authenticated user data in your controllers:

```php
public function dashboard(Request $request)
{
    $token = session('auth_token');
    $user = session('auth_user');
    $loginTime = session('login_time');

    if (!$token || !$user) {
        return redirect()->route('auth.login');
    }

    return view('dashboard', [
        'user' => $user,
    ]);
}
```

### Logout

Create a logout button:

```html
<form action="{{ route('auth.logout') }}" method="POST">
    @csrf
    <button type="submit" class="btn btn-danger">Logout</button>
</form>
```

Or logout programmatically:

```php
return redirect()->route('auth.logout');
```

## ⚙️ Configuration

The `config/authservice.php` file contains all configurable options:

```php
return [
    // Auth Service Base URL
    'auth_service_base_url' => env('AUTH_SERVICE_BASE_URL', 'http://localhost:8000'),

    // Service API Key
    'auth_service_api_key' => env('AUTH_SERVICE_API_KEY'),

    // Service Slug
    'service_slug' => env('AUTH_SERVICE_SLUG'),

    // Request Timeout (seconds)
    'timeout' => env('AUTH_SERVICE_TIMEOUT', 30),

    // Optional: Required roles for login (null = allow all)
    'login_roles' => env('AUTH_SERVICE_LOGIN_ROLES') ?
        explode(',', env('AUTH_SERVICE_LOGIN_ROLES')) : null,

    // Callback URL after authentication
    'callback_url' => env('AUTH_SERVICE_CALLBACK_URL', '/auth/callback'),

    // Redirect destination after successful login
    'redirect_after_login' => env('AUTH_SERVICE_REDIRECT_AFTER_LOGIN', '/dashboard'),
];
```

## 🎨 Customizing Views

### Login Page

After publishing views, customize the login page at:
`resources/views/vendor/authservice/auth/login.blade.php`

### Redirect Page

Customize the redirect page at:
`resources/views/vendor/authservice/auth/redirect-with-token.blade.php`

## 🔐 Role-Based Login

Restrict login to specific roles by setting the `AUTH_SERVICE_LOGIN_ROLES` environment variable:

```conf
# Allow only admins
AUTH_SERVICE_LOGIN_ROLES=admin

# Allow multiple roles
AUTH_SERVICE_LOGIN_ROLES=admin,manager,supervisor
```

Users without the required roles will see an error message when attempting to log in.

## 🧪 Service Classes

### AuthServiceClient

The AuthServiceClient provides both high-level authentication methods and low-level HTTP verb utilities for flexible API integration.

#### HTTP Verb Methods

Make direct API calls using HTTP verbs:

```php
use AuthService\Helper\Services\AuthServiceClient;

$client = app(AuthServiceClient::class);

// GET request
$services = $client->get('services');
$users = $client->get('users', [
    'query' => ['role' => 'admin'],
    'auth_token' => $token
]);

// POST request
$result = $client->post('services/register', [
    'name' => 'My Service',
    'slug' => 'my-service'
]);

// PUT request
$updated = $client->put('users/123', [
    'name' => 'New Name'
], [
    'auth_token' => $token
]);

// PATCH request
$patched = $client->patch('users/123', [
    'email' => 'new@email.com'
], [
    'auth_token' => $token
]);

// DELETE request
$deleted = $client->delete('sessions/abc123', [
    'auth_token' => $token
]);
```

**Available options:**
- `headers` - Custom HTTP headers
- `query` - URL query parameters
- `auth_token` - Bearer token for Authorization
- `throw` - Whether to throw exceptions (default: true)
- `log_context` - Additional logging context

📖 **See [AuthServiceClient Usage Guide](docs/AuthServiceClient_Usage.md) for detailed examples and best practices.**

#### High-Level Authentication Methods

```php
// Generate landing session
$response = $client->generateLanding('login', 'https://yourapp.com/auth/callback', [
    'metadata' => ['key' => 'value']
]);

// Generate role-restricted login
$response = $client->generateRoleRestrictedLoginLanding(
    'https://yourapp.com/auth/callback',
    ['admin', 'manager']
);

// Get landing status
$status = $client->getLandingStatus($sessionId);

// Get authenticated user
$user = $client->me($token);

// Logout user
$result = $client->logout($token);

// Check service trust
$trustResult = $client->checkTrust([
    'calling_service_key' => 'key',
    'target_service_slug' => 'slug'
]);
```

## 🔒 Security Features

### Token Storage
- Tokens stored in both localStorage and sessionStorage
- Automatic token cleanup on logout
- Secure transmission via POST requests

### Service Trust Validation
- Validates service-to-service API calls
- Supports trust permissions
- Automatic internal call detection

### Role-Based Access
- Service-scoped role support (`service-slug:role`)
- Global admin role support
- Multiple role checking with OR logic

## 📋 Requirements

- **PHP 8.2+**
- **Laravel 12.0+**
- **GuzzleHTTP 7.0+**

## 🆘 Troubleshooting

### Login page not showing
- Ensure config is published: `php artisan vendor:publish --tag=authservice-config`
- Check environment variables are set correctly
- Verify `AUTH_SERVICE_BASE_URL` is accessible

### Middleware not working
- Clear config cache: `php artisan config:clear`
- Check middleware aliases are registered in `app/Http/Kernel.php`
- Verify session contains `auth_user` data

### Role-based access not working
- Check user roles in session: `dd(session('auth_user')['user']['roles'])`
- Verify role names match exactly (case-sensitive)
- Check for service-scoped roles: `your-service:role-name`

## 📄 License

MIT License - see the LICENSE file for details.

## 🤝 Related Packages

- [auth-service-wrapper-laravel](https://github.com/Shirahcan/auth-service-wrapper-laravel) - Full-featured API wrapper for the auth service

## 📞 Support

For issues and questions:
- GitHub Issues: https://github.com/Shirahcan/auth-service-helper/issues

---

**Built with ❤️ for Laravel developers using centralized authentication.**