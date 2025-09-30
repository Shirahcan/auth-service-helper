# User Model Documentation

The User model provides a Laravel-friendly interface for working with authenticated users from the Authentication Microservice.

## Overview

The `AuthService\Helper\Models\User` class implements Laravel's `Authenticatable` contract and integrates seamlessly with Laravel's authentication system, allowing you to use familiar patterns like `auth()->user()` and `auth()->id()`.

## Features

- **Laravel Auth Integration**: Works with `auth()` helper and Auth facade
- **Session-Based**: Constructs user data from session (no database required)
- **Role Management**: Comprehensive role checking including service-scoped roles
- **Array Access**: Implements ArrayAccess for flexible data access
- **Magic Methods**: Access attributes via properties (`$user->name`)

## Basic Usage

### Getting the Current User

```php
// Using the authservice guard
$user = auth('authservice')->user();

// Using the helper function
$user = authservice_user();

// Get user ID
$userId = auth('authservice')->id();
$userId = authservice_id();

// Check if authenticated
$isAuthenticated = auth('authservice')->check();
$isAuthenticated = authservice_check();

// Check if guest
$isGuest = auth('authservice')->guest();
$isGuest = authservice_guest();
```

### Setting the Default Guard

To avoid specifying the guard every time, set it as the default in your `config/auth.php`:

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
$isAuthenticated = auth()->check();
```

## Accessing User Attributes

### Property Access

```php
$user = authservice_user();

// Access attributes as properties
$name = $user->name;
$email = $user->email;
$id = $user->id;

// Using getAttribute method
$name = $user->getAttribute('name');
$email = $user->getAttribute('email', 'default@example.com'); // with default
```

### Array Access

```php
// Access as array
$name = $user['name'];
$email = $user['email'];

// Check if attribute exists
if (isset($user['name'])) {
    echo $user['name'];
}
```

### Getting All Attributes

```php
// As array
$attributes = $user->toArray();

// As JSON
$json = $user->toJson();
$json = json_encode($user); // Also works via JsonSerializable
```

## Role Management

### Basic Role Checking

```php
$user = authservice_user();

// Check if user has a specific role
if ($user->hasRole('admin')) {
    // User is an admin
}

// Check if user has any of the given roles
if ($user->hasAnyRole(['admin', 'moderator'])) {
    // User is either admin or moderator
}

// Check if user has all of the given roles
if ($user->hasAllRoles(['admin', 'super-admin'])) {
    // User has both admin and super-admin roles
}

// Get all user roles
$roles = $user->getRoles();
// Returns: ['admin', 'documents-service:editor', ...]
```

### Service-Scoped Roles

The auth service supports service-scoped roles in the format `service-slug:role-name`. The User model makes checking these easy:

```php
$user = authservice_user();

// Check if user has a service-scoped role
if ($user->hasServiceRole('documents-service', 'editor')) {
    // User can edit documents
}
```

The `hasServiceRole()` method checks for:
1. Exact role match (`editor`)
2. Service-scoped role match (`documents-service:editor`)
3. Global admin roles (`super-admin`, `admin`)

### Service Administration

```php
$user = authservice_user();

// Check if user can manage a specific service
if ($user->canManageService($serviceId)) {
    // User can manage the service
}

// Check if user is auth service admin (super-admin)
if ($user->isAuthServiceAdmin()) {
    // User is a super admin
}

// Get user type
$userType = $user->getUserType();
// Returns: 'super_admin', 'admin', or 'user'
```

## Service Metadata

Access service-specific metadata stored for the user:

```php
$user = authservice_user();

// Get all service metadata
$metadata = $user->getServiceMetadata();

// Get specific metadata key
$preferences = $user->getServiceMetadata('preferences');

// Get metadata with default value
$theme = $user->getServiceMetadata('theme', 'light');
```

## Email Verification

```php
$user = authservice_user();

// Check if email is verified
if ($user->hasVerifiedEmail()) {
    // Email is verified
}
```

## Creating User Instances

Typically, the User model is created automatically by the authentication system. However, you can create instances manually if needed:

```php
use AuthService\Helper\Models\User;

// From session data
$user = User::createFromSession($sessionData);

// Direct instantiation
$user = new User([
    'id' => 1,
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'roles' => ['admin'],
]);
```

## Middleware Usage

### Role-Based Route Protection

```php
use Illuminate\Support\Facades\Route;

// Require admin role
Route::middleware('authservice.role:admin')->group(function () {
    Route::get('/admin', [AdminController::class, 'index']);
});

// Require any of multiple roles
Route::middleware('authservice.role:admin,moderator')->group(function () {
    Route::get('/moderation', [ModerationController::class, 'index']);
});
```

### In Controllers

```php
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct()
    {
        // Require authentication
        $this->middleware('auth:authservice');

        // Require specific role
        $this->middleware('authservice.role:editor')->only(['edit', 'update']);
    }

    public function index(Request $request)
    {
        $user = auth('authservice')->user();

        // Check service-scoped role
        if ($user->hasServiceRole('documents-service', 'editor')) {
            // Show edit interface
        }
    }
}
```

## Blade Views

### Displaying User Information

```blade
@auth('authservice')
    <p>Welcome, {{ authservice_user()->name }}!</p>
    <p>Email: {{ authservice_user()->email }}</p>
@endauth

@guest('authservice')
    <a href="{{ route('auth.login') }}">Login</a>
@endguest
```

### Role-Based Content

```blade
@auth('authservice')
    @php
        $user = authservice_user();
    @endphp

    @if($user->hasRole('admin'))
        <a href="/admin">Admin Panel</a>
    @endif

    @if($user->hasServiceRole('documents-service', 'editor'))
        <a href="/documents/edit">Edit Documents</a>
    @endif
@endauth
```

## Available Helper Functions

```php
// Get authenticated user
$user = authservice_user();

// Get user ID
$id = authservice_id();

// Check if authenticated
$authenticated = authservice_check();

// Check if guest
$guest = authservice_guest();

// Get guard instance
$guard = authservice_guard();
```

## User Model Methods Reference

### Authentication Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `getAuthIdentifierName()` | `string` | Get the name of the unique identifier field |
| `getAuthIdentifier()` | `mixed` | Get the unique identifier value |
| `getAuthPassword()` | `string` | Get password (not used, returns empty string) |
| `getRememberToken()` | `?string` | Get remember token (not used, returns null) |
| `setRememberToken($value)` | `void` | Set remember token (no-op) |
| `getRememberTokenName()` | `?string` | Get remember token field name (returns null) |

### Role Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `hasRole($role)` | `bool` | Check if user has a specific role |
| `hasServiceRole($serviceSlug, $roleName)` | `bool` | Check if user has a service-scoped role |
| `hasAnyRole($roles)` | `bool` | Check if user has any of the given roles |
| `hasAllRoles($roles)` | `bool` | Check if user has all of the given roles |
| `getRoles()` | `array` | Get all user roles |
| `getUserType()` | `string` | Get user type (super_admin, admin, or user) |
| `canManageService($serviceId)` | `bool` | Check if user can manage a service |
| `isAuthServiceAdmin()` | `bool` | Check if user is auth service admin |

### Attribute Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `getAttribute($key, $default)` | `mixed` | Get an attribute value |
| `setAttribute($key, $value)` | `void` | Set an attribute value |
| `getServiceMetadata($key, $default)` | `mixed` | Get service metadata |
| `hasVerifiedEmail()` | `bool` | Check if email is verified |

### Conversion Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `toArray()` | `array` | Convert user to array |
| `toJson($options)` | `string` | Convert user to JSON |
| `jsonSerialize()` | `array` | Get data for JSON serialization |

## Session Data Structure

The User model expects session data in this format:

```php
[
    'id' => 1,
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'email_verified_at' => '2024-01-15 10:30:00',
    'roles' => ['admin', 'documents-service:editor'],
    'service_metadata' => [
        'preferences' => [...],
        'theme' => 'dark',
    ],
    'admin_service_permissions' => [1, 2, 3],
    // ... other user attributes
]
```

The constructor also handles the auth service response structure where user data is nested:

```php
[
    'user' => [
        'id' => 1,
        'name' => 'John Doe',
        // ... user attributes
    ]
]
```

## Best Practices

1. **Use Helper Functions**: The helper functions (`authservice_user()`, etc.) provide convenient shortcuts.

2. **Set Default Guard**: Configure `authservice` as the default guard to use `auth()->user()` directly.

3. **Service-Scoped Roles**: Use `hasServiceRole()` for checking service-specific permissions.

4. **Middleware Protection**: Use `authservice.role` middleware to protect routes.

5. **Check Before Access**: Always check authentication before accessing user data:

```php
if (authservice_check()) {
    $user = authservice_user();
    // Safe to access user data
}
```

## Troubleshooting

### User is null

**Problem**: `authservice_user()` returns null.

**Solution**:
- Ensure the user is logged in via the auth service
- Check that session data exists: `session('auth_user')`
- Verify the authservice guard is properly configured

### Roles not working

**Problem**: `hasRole()` returns false when it should return true.

**Solution**:
- Check the exact role name in the session
- Remember that service-scoped roles use the format `service:role`
- Use `hasServiceRole()` for service-scoped role checks
- Verify the user's roles: `$user->getRoles()`

### Attributes not accessible

**Problem**: Cannot access user attributes.

**Solution**:
- Check the session data structure: `session('auth_user')`
- Use `getAttribute()` with a default value
- Verify the attribute exists: `isset($user['attribute_name'])`
