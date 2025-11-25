# Auth Service Helper for Laravel

A lightweight Laravel 12 package for easy integration with the Authentication Microservice. Provides web authentication flows, middleware for route protection, and UI components for seamless user experience.

## 🚀 Features

### 🔐 Web Authentication Flows
- **Drop-in Login Page**: Beautiful, responsive login interface with role-based filtering
- **Secure Token Management**: Automatic localStorage/sessionStorage token handling
- **Session Management**: Complete authentication session lifecycle management
- **Callback Handling**: Seamless integration with auth service callbacks

### 👤 Laravel Auth Integration
- **User Model**: Full `Authenticatable` implementation with role checking
- **Custom Guard**: Session-based auth guard (`authservice`)
- **Helper Functions**: Convenient shortcuts (`authservice_user()`, `authservice_id()`)
- **Familiar Patterns**: Use `auth()->user()` and `auth()->check()` as usual

### 🔍 Eloquent-Like Query Builder
- **Chainable Queries**: Build complex queries like `User::where('is_admin', true)->orderBy('name')->get()`
- **Laravel-Style API**: Familiar methods (`find`, `where`, `first`, `paginate`, `count`)
- **User Collection**: Extended collection with user-specific methods
- **CRUD Operations**: Create, update, and delete users via API
- **Named Scopes**: Convenient methods (`admins()`, `recent()`, `search()`)

### 🛡️ Security Middleware
- **Authenticate**: Simple authentication guard for protected routes
- **HasRoleMiddleware**: Advanced role-based access control with service-scoped roles, global admin support, and auth guard integration
- **TrustedServiceMiddleware**: Validate service-to-service trust relationships

### 🔑 Service Trust API Keys
- **TrustedServiceClient**: Easy service-to-service HTTP requests with automatic trust key authentication
- **Facade Support**: Simple `TrustedService::makeTrustedGetRequest()` syntax
- **Permission-Based Access**: Protect routes with required permissions
- **Flexible Configuration**: Environment variables or config-based setup
- **Comprehensive Error Handling**: Clear error messages for debugging

### 🎨 Blade Components
- **AccountSwitcherLoader**: Loads the account switcher JavaScript from auth service
- **AccountSwitcher**: Secure iframe-based account switcher with session synchronization
- **AccountAvatar**: Compact clickable avatar for NAV bars with session sync

### ⚡ Key Benefits
- **Lightweight**: Focused on web flows, not full API wrapping
- **Easy Integration**: Install and configure in minutes
- **Laravel 12 Ready**: Built specifically for Laravel 12
- **Responsive UI**: Modern, mobile-friendly authentication pages
- **Role-Based Access**: Optional role filtering for login pages
- **Session-Based**: No database required for authentication
- **GitHub Installation**: Install directly from GitHub repository

## 📦 Installation

### Quick Install (Recommended)

#### Step 1: Install via Composer

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

#### Step 2: Run the Install Command

Use the install command to set up the package quickly:

```bash
# Basic installation (publishes config only)
php artisan authservice:install

# Install with views
php artisan authservice:install --with-views

# Install and configure auth guard in config/auth.php
php artisan authservice:install --configure-guard

# Install and set authservice as default guard
php artisan authservice:install --as-default

# Combine flags as needed
php artisan authservice:install --with-views --as-default
```

**Available Flags:**
- `--with-views`: Publish views in addition to config files
- `--configure-guard`: Add authservice guard and provider to `config/auth.php`
- `--as-default`: Set authservice as the default guard (implies `--configure-guard`)

#### Step 3: Configure Environment Variables

Add to your `.env` file:

```env
# Required
AUTH_SERVICE_BASE_URL=http://localhost:8000
AUTH_SERVICE_API_KEY=your_service_api_key_here
AUTH_SERVICE_SLUG=your-service-slug

# Optional
AUTH_SERVICE_TIMEOUT=30
AUTH_SERVICE_LOGIN_ROLES=admin,manager
AUTH_SERVICE_CALLBACK_URL=/auth/callback
AUTH_SERVICE_REDIRECT_AFTER_LOGIN=/dashboard
```

### Manual Installation

If you prefer manual setup or need more control:

#### Step 1: Install via Composer

Follow the composer installation steps above.

#### Step 2: Publish Configuration

```bash
php artisan vendor:publish --tag=authservice-config
```

#### Step 3: Configure Environment Variables

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

#### Step 4 (Optional): Publish Views for Customization

```bash
php artisan vendor:publish --tag=authservice-views
```

Views will be published to `resources/views/vendor/authservice/`.

#### Step 5 (Optional): Configure Auth Guard

If you want to use the authservice guard, add to `config/auth.php`:

```php
'defaults' => [
    'guard' => 'authservice', // Optional: Set as default guard
    'passwords' => 'users',
],

'guards' => [
    'authservice' => [
        'driver' => 'authservice',
        'provider' => 'authservice',
    ],
    // ... other guards
],

'providers' => [
    'authservice' => [
        'driver' => 'authservice',
    ],
    // ... other providers
],
```

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

The package provides three middleware for different levels of protection:

- **`authservice.auth`**: Require authentication (any logged-in user)
- **`authservice.role`**: Require specific role(s) with service-scoped support
- **`authservice.trusted`**: Validate service-to-service trust relationships

#### Authenticate Middleware

Require users to be authenticated to access routes. Use this when you just need to verify a user is logged in, regardless of their roles.

```php
use Illuminate\Support\Facades\Route;

// Require authentication for a single route
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('authservice.auth');

// Protect multiple routes with a group
Route::middleware(['authservice.auth'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/profile', [ProfileController::class, 'update']);
});

// Combine with other middleware
Route::get('/user/posts', [PostController::class, 'index'])
    ->middleware(['authservice.auth', 'throttle:60,1']);
```

**When to use:**
- ✅ Any route that requires a logged-in user
- ✅ Profile pages, dashboards, user-specific content
- ✅ When role checking is not needed

#### HasRoleMiddleware

Protect routes by requiring specific roles. The middleware uses the `authservice` guard and supports service-scoped roles, global admin roles, and standard role checking.

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

**How Role Checking Works:**

When `AUTH_SERVICE_SLUG` is configured, the middleware performs **service-scoped role checking**:

1. **Exact role match**: User has role `editor` → Grants access to `authservice.role:editor`
2. **Service-scoped roles**: User has `documents-service:editor` → Grants access to `authservice.role:editor` (when `AUTH_SERVICE_SLUG=documents-service`)
3. **Global admin roles**: User has `super-admin` or `admin` → Grants access to ANY role requirement

When `AUTH_SERVICE_SLUG` is NOT configured, falls back to **standard role checking** (exact match only).

**Examples:**

```php
// User has role: 'editor'
// ✅ Passes: authservice.role:editor
// ❌ Fails: authservice.role:admin

// User has role: 'documents-service:editor'
// (with AUTH_SERVICE_SLUG=documents-service)
// ✅ Passes: authservice.role:editor
// ✅ Passes: authservice.role:documents-service:editor

// User has role: 'super-admin'
// ✅ Passes: authservice.role:editor (or any other role)
// ✅ Passes: authservice.role:admin,manager (any role requirement)
```

**Benefits of Service-Scoped Roles:**

- Same role name across different services (e.g., `editor` in documents-service vs media-service)
- Centralized role management with service-specific permissions
- Global admins automatically have access to all services

**When to use:**
- ✅ Routes that require specific role(s)
- ✅ Admin panels, management pages
- ✅ Feature-specific access control

#### Middleware Comparison

| Middleware | Purpose | Example Use Case |
|------------|---------|-----------------|
| `authservice.auth` | Any authenticated user | Dashboard, profile, settings |
| `authservice.role:admin` | Specific role required | Admin panel, user management |
| `authservice.role:editor,admin` | One of multiple roles | Content editing, moderation |
| `authservice.trusted` | Service-to-service API | Cross-service API calls |

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

The package provides three Blade components for account management and user display.

#### Account Switcher (IFRAME-based)

A secure, iframe-based account switcher that provides multi-account session management with automatic synchronization between the iframe widget and your Laravel application.

**Features:**
- Secure JWT-based token authentication
- PostMessage protocol for cross-origin communication
- Automatic session synchronization with Laravel backend
- Material Design interactive dialogs
- Auto-resize support
- Multi-account session management
- Automatic page reload on account changes

**Basic Usage:**

```blade
<!-- Simple usage with defaults -->
<x-authservice-account-switcher />

<!-- Full configuration -->
<x-authservice-account-switcher
    :auth-url="config('authservice.auth_service_base_url')"
    :api-key="config('authservice.auth_service_api_key')"
    :service-slug="config('authservice.service_slug')"
    container-id="account-switcher"
    :auto-resize="true"
    :min-height="200"
    :max-height="600"
    :dialogs-enabled="true"
    :reload-on-switch="true"
    :spa-support="false"
/>
```

**Props:**
- `auth-url` - Auth service base URL (default: from config)
- `api-key` - Auth service API key (default: from config)
- `service-slug` - Service identifier (default: from config)
- `container-id` - Container element ID (default: auto-generated)
- `auto-resize` - Enable automatic iframe height adjustment (default: true)
- `min-height` - Minimum iframe height in pixels (default: 200)
- `max-height` - Maximum iframe height in pixels (default: null/unlimited)
- `dialogs-enabled` - Enable Material Design dialogs (default: true)
- `reload-on-switch` - Reload page when account switches (default: true)
- `spa-support` - Enable SPA navigation support (default: false)

**How It Works:**
1. Embeds a secure iframe from the auth-service
2. Listens for SESSION_CHANGED messages via postMessage
3. Syncs session data to Laravel backend via `/auth/sync-session`
4. Automatically reloads page when account changes detected
5. Supports add account, switch account, and logout operations

#### Account Avatar

A compact, clickable avatar component for NAV bars that displays the current user's avatar and synchronizes with the iframe account switcher.

**Features:**
- Displays user avatar (image or initials) when logged in
- Shows generic profile icon when logged out or iframe not loaded
- Listens for SESSION_CHANGED messages from iframe
- Configurable size and click behavior
- Smooth transitions and hover effects

**Basic Usage:**

```blade
<!-- Simple usage with defaults (40px) -->
<x-authservice-account-avatar />

<!-- Custom size -->
<x-authservice-account-avatar :size="48" />

<!-- Toggle dropdown on click -->
<x-authservice-account-avatar target-id="account-dropdown" />

<!-- Custom click handler -->
<x-authservice-account-avatar onClick="showAccountMenu()" />

<!-- Full example in NAV -->
<nav>
    <div class="nav-items">
        <x-authservice-account-avatar
            :size="36"
            target-id="account-switcher-dropdown"
        />
    </div>
</nav>

<!-- Hidden dropdown with account switcher -->
<div id="account-switcher-dropdown" style="display: none;">
    <x-authservice-account-switcher />
</div>
```

**Props:**
- `auth-url` - Auth service URL for origin verification (default: from config)
- `size` - Avatar diameter in pixels (default: 40)
- `container-id` - Unique container ID (default: auto-generated)
- `target-id` - Element ID to toggle visibility on click (optional)
- `onClick` - Custom JavaScript function to execute on click (optional)

**How It Works:**
1. Displays generic profile icon by default
2. Listens for SESSION_CHANGED postMessage from iframe
3. Updates to show user avatar/initials when logged in
4. Emits 'avatar-clicked' custom event on click
5. Optionally toggles visibility of target element

#### Account Switcher Loader (Legacy)

**Note:** This loader is for the deprecated custom account switcher implementation. For new projects, use the iframe-based `<x-authservice-account-switcher />` component instead.

```blade
<x-authservice-account-switcher-loader />
```

### Laravel Auth Integration

The package provides a custom `User` model that integrates with Laravel's authentication system, allowing you to use familiar patterns like `auth()->user()` and `auth()->id()`.

#### Using the AuthService Guard

```php
// Get authenticated user
$user = auth('authservice')->user();

// Get user ID
$userId = auth('authservice')->id();

// Check if authenticated
if (auth('authservice')->check()) {
    // User is authenticated
}

// Check if guest
if (auth('authservice')->guest()) {
    // User is not authenticated
}
```

#### Helper Functions

For convenience, use the provided helper functions:

```php
// Get authenticated user
$user = authservice_user();

// Get user ID
$id = authservice_id();

// Check if authenticated
if (authservice_check()) {
    // User is authenticated
}

// Check if guest
if (authservice_guest()) {
    // User is not authenticated
}
```

#### Setting the Default Guard

To use `auth()->user()` directly without specifying the guard, set it as default in your `config/auth.php`:

```php
'defaults' => [
    'guard' => 'authservice',
    'passwords' => 'users',
],

'guards' => [
    'authservice' => [
        'driver' => 'authservice',
        'provider' => 'authservice',
    ],
],

'providers' => [
    'authservice' => [
        'driver' => 'authservice',
    ],
],
```

Then you can use:

```php
$user = auth()->user();
$userId = auth()->id();
```

#### Accessing User Attributes

```php
$user = authservice_user();

// Access as properties
$name = $user->name;
$email = $user->email;
$id = $user->id;

// Access as array
$name = $user['name'];

// Get all attributes
$attributes = $user->toArray();
```

#### Role Checking

```php
$user = authservice_user();

// Check if user has a role
if ($user->hasRole('admin')) {
    // User is an admin
}

// Check if user has service-scoped role
if ($user->hasServiceRole('documents-service', 'editor')) {
    // User can edit documents
}

// Check if user has any of multiple roles
if ($user->hasAnyRole(['admin', 'moderator'])) {
    // User has at least one of the roles
}

// Get all user roles
$roles = $user->getRoles();
```

#### In Controllers

```php
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct()
    {
        // Require authentication
        $this->middleware('auth:authservice');
    }

    public function index(Request $request)
    {
        $user = authservice_user();

        // Check roles
        if ($user->hasRole('admin')) {
            // Show admin dashboard
        }

        return view('dashboard', [
            'user' => $user,
        ]);
    }
}
```

#### In Blade Views

```blade
@auth('authservice')
    <p>Welcome, {{ authservice_user()->name }}!</p>

    @if(authservice_user()->hasRole('admin'))
        <a href="/admin">Admin Panel</a>
    @endif
@endauth

@guest('authservice')
    <a href="{{ route('auth.login') }}">Login</a>
@endguest
```

📖 **See [User Model Documentation](docs/User_Model.md) for complete API reference and advanced usage.**

### Querying Users

The package provides an Eloquent-like query builder for fetching users from the auth service API.

#### Basic Queries

```php
use AuthService\Helper\Models\User;

// Find user by UUID
$user = User::find('uuid-here');

// Find or throw exception
$user = User::findOrFail('uuid-here');

// Get all users
$users = User::all();

// Get first matching user
$user = User::firstWhere('email', 'john@example.com');
```

#### Where Clauses

```php
// Simple where
$admins = User::where('is_admin', true)->get();

// Where with operator
$users = User::where('email', 'like', '%@example.com%')->get();

// Multiple where clauses
$users = User::where('is_admin', true)
    ->where('email_verified', true)
    ->get();

// Complex queries
$users = User::where('is_admin', false)
    ->whereNotNull('email_verified_at')
    ->orderBy('created_at', 'desc')
    ->limit(50)
    ->get();

// WhereIn - match any value in array
$userIds = ['uuid-1', 'uuid-2', 'uuid-3'];
$users = User::whereIn('id', $userIds)->get();

// WhereNotIn - exclude values in array
$excludedIds = ['uuid-4', 'uuid-5'];
$users = User::whereNotIn('id', $excludedIds)->get();

// Combining whereIn with other conditions
$users = User::whereIn('id', $userIds)
    ->where('is_admin', false)
    ->get();
```

#### Named Scopes

```php
// Get admin users
$admins = User::admins();

// Get recently active users
$recent = User::recent(7, 50); // Last 7 days, limit 50

// Get unverified users
$unverified = User::unverified();

// Search users
$results = User::search('john');
```

#### Pagination

```php
// Paginate results
$users = User::where('is_admin', false)->paginate(15);

// Access pagination data
foreach ($users as $user) {
    echo $user->name;
}

echo $users->total(); // Total count
echo $users->currentPage(); // Current page
```

#### CRUD Operations

```php
// Create user
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => 'secret123'
]);

// Update user
$user = User::find('uuid-here');
$user->update(['name' => 'Jane Doe']);

// Delete user
$user->delete();

// Bulk operations
User::updateMany(['uuid1', 'uuid2'], ['is_admin' => true]);
User::deleteMany(['uuid1', 'uuid2']);
```

#### Working with Collections

```php
$users = User::all();

// Filter collections
$admins = $users->admins();
$verified = $users->verified();
$byService = $users->byService('service-uuid');

// Get user data
$ids = $users->ids(); // Collection of UUIDs
$emails = $users->emails(); // Collection of emails

// Sort collections
$sorted = $users->sortByName();
$sorted = $users->sortByLastLogin();

// Get statistics
$stats = $users->statistics();
// Returns: ['total' => 100, 'admins' => 5, 'verified' => 80, ...]

// Group by role
$grouped = $users->groupByRole();
```

#### Instance Methods

```php
$user = User::find('uuid-here');

// Refresh data from API
$user->refresh();

// Get user's sessions
$sessions = $user->sessions();

// Get user's roles (refreshed from API)
$roles = $user->roles();

// Get user's metadata
$metadata = $user->metadata();
```

📖 **See [User Query Builder Documentation](docs/User_Query_Builder.md) for complete query builder API and advanced examples.**

### Session Management

You can also access raw session data if needed:

```php
public function dashboard(Request $request)
{
    $token = session('auth_token');
    $userData = session('auth_user');
    $loginTime = session('login_time');

    if (!$token || !$userData) {
        return redirect()->route('auth.login');
    }

    return view('dashboard', [
        'token' => $token,
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

## 🔑 Service Trust API Keys (Service-to-Service Communication)

### Overview
The Service Trust API Keys system enables secure service-to-service communication. Instead of sharing credentials or using complex OAuth flows, services can use pre-generated trust keys to authenticate requests.

### TrustedServiceClient

The `TrustedServiceClient` class provides an easy way to make authenticated HTTP requests to trusted services.

#### Basic Usage

```php
use AuthService\Helper\Services\TrustedServiceClient;

$client = new TrustedServiceClient();

// Make a GET request
$response = $client->makeTrustedGetRequest(
    'documents-service',           // Service slug
    '/api/v1/documents',          // Endpoint
    ['status' => 'active'],       // Query parameters (optional)
    [],                           // Additional headers (optional)
    $userBearerToken              // Bearer token (optional)
);

if ($response->successful()) {
    $documents = $response->json('data');
}
```

#### HTTP Methods

```php
// GET request
$response = $client->makeTrustedGetRequest($serviceSlug, $endpoint, $queryParams);

// POST request
$response = $client->makeTrustedPostRequest($serviceSlug, $endpoint, $data);

// PUT request
$response = $client->makeTrustedPutRequest($serviceSlug, $endpoint, $data);

// PATCH request
$response = $client->makeTrustedPatchRequest($serviceSlug, $endpoint, $data);

// DELETE request
$response = $client->makeTrustedDeleteRequest($serviceSlug, $endpoint, $data);
```

#### Using the Facade

```php
use AuthService\Helper\Facades\TrustedService;

$response = TrustedService::makeTrustedGetRequest(
    'documents-service',
    '/api/v1/documents'
);
```

#### Configuration Options

```php
$client = new TrustedServiceClient();

// Set custom timeout (default: 30 seconds)
$client->setTimeout(60);

// Set retry attempts (default: 2 retries with 100ms delay)
$client->setRetries(3, 200);

// Disable automatic exception throwing on HTTP errors
$client->throwOnError(false);

// Chain configuration
$response = $client
    ->setTimeout(60)
    ->setRetries(3, 200)
    ->throwOnError(false)
    ->makeTrustedGetRequest('service', '/endpoint');
```

### Configuration

Add trusted services to your `config/authservice.php`:

```php
return [
    // ... other config ...

    'trust_keys' => [
        'documents-service' => env('DOCUMENTS_SERVICE_TRUST_KEY'),
        'consultancy-service' => env('CONSULTANCY_SERVICE_TRUST_KEY'),
    ],

    'service_urls' => [
        'documents-service' => env('DOCUMENTS_SERVICE_SERVICE_URL', 'http://localhost:8001'),
        'consultancy-service' => env('CONSULTANCY_SERVICE_SERVICE_URL', 'http://localhost:8002'),
    ],

    // Optional API keys for additional authentication
    'api_keys' => [
        'documents-service' => env('DOCUMENTS_SERVICE_API_KEY'),
    ],
];
```

Or use environment variables directly (the client will look these up automatically):

```env
DOCUMENTS_SERVICE_TRUST_KEY=your-trust-key-here
DOCUMENTS_SERVICE_SERVICE_URL=https://documents.example.com
DOCUMENTS_SERVICE_API_KEY=optional-api-key
```

### TrustedServiceMiddleware

Protect your routes from unauthorized service requests using the `TrustedServiceMiddleware`.

#### Basic Route Protection

```php
// In routes/api.php
Route::middleware(['authservice.trusted'])->group(function () {
    Route::get('/internal/users', [UserController::class, 'index']);
});
```

#### With Permission Requirements

```php
// Single permission
Route::middleware(['authservice.trusted:users:read'])->group(function () {
    Route::get('/internal/users', [UserController::class, 'index']);
});

// Multiple permissions (all required)
Route::middleware(['authservice.trusted:users:read,users:write'])->group(function () {
    Route::post('/internal/users', [UserController::class, 'store']);
});
```

#### Accessing Trust Information in Controllers

```php
public function index(Request $request)
{
    // Get trust data
    $trustData = $request->get('service_trust');

    // Access calling service information
    $callingServiceSlug = $trustData['calling_service']['slug'];
    $callingServiceName = $trustData['calling_service']['name'];

    // Access permissions
    $permissions = $trustData['permissions'];

    return response()->json(['users' => User::all()]);
}
```

### Security Best Practices

1. **Never commit trust keys** - Always use environment variables
2. **Rotate keys regularly** - Generate new keys and revoke old ones periodically
3. **Use minimal permissions** - Only grant the permissions each service actually needs
4. **Monitor usage** - Log all service-to-service requests for auditing
5. **Set expiration dates** - Trust keys should have reasonable expiration periods

### Error Handling

```php
use Illuminate\Http\Client\RequestException;

try {
    $response = $client->makeTrustedGetRequest('service', '/endpoint');

    if ($response->successful()) {
        return $response->json();
    }

    // Handle HTTP error responses
    if ($response->status() === 401) {
        // Invalid or expired trust key
    } elseif ($response->status() === 403) {
        // Insufficient permissions
    }
} catch (RequestException $e) {
    // Network or connection error
    Log::error('Service request failed', [
        'error' => $e->getMessage()
    ]);
} catch (\Exception $e) {
    // Configuration error (missing trust key or service URL)
    Log::error('Configuration error', [
        'error' => $e->getMessage()
    ]);
}
```

### Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| "Trust key not configured for service: {slug}" | Missing trust key in config/env | Add `{SLUG}_TRUST_KEY` to `.env` |
| "Service URL not configured for: {slug}" | Missing service URL in config/env | Add `{SLUG}_SERVICE_URL` to `.env` |
| HTTP 401 | Invalid or expired trust key | Verify key is correct and not expired |
| HTTP 403 | Insufficient permissions | Check trust key permissions |

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