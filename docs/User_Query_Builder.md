# User Query Builder Documentation

The User Query Builder provides a Laravel Eloquent-like interface for querying users from the Authentication Microservice API. It allows you to build complex queries using method chaining and execute them efficiently.

## Table of Contents

- [Basic Usage](#basic-usage)
- [Query Builder Methods](#query-builder-methods)
- [Static Methods](#static-methods)
- [Instance Methods](#instance-methods)
- [User Collection](#user-collection)
- [Advanced Examples](#advanced-examples)
- [Best Practices](#best-practices)

## Basic Usage

### Finding Users

```php
use AuthService\Helper\Models\User;

// Find user by UUID
$user = User::find('uuid-here');

// Find or throw exception
$user = User::findOrFail('uuid-here');

// Find first matching user
$user = User::firstWhere('email', 'john@example.com');
```

### Getting All Users

```php
// Get all users (paginated to 100)
$users = User::all();

// Get all users with custom limit
$users = User::all(50);
```

### Basic Where Queries

```php
// Simple where clause
$users = User::where('is_admin', true)->get();

// Where with operator
$users = User::where('email', 'like', '%@example.com%')->get();

// Multiple where clauses
$users = User::where('is_admin', true)
    ->where('email_verified', true)
    ->get();
```

## Query Builder Methods

### where()

Add a where clause to the query.

```php
// Basic equality
User::where('is_admin', true)->get();
User::where('name', 'John Doe')->get();

// With operator
User::where('email', 'like', '%@example.com%')->get();
User::where('created_at', '>', '2024-01-01')->get();

// Operators supported: =, !=, like, >, <, >=, <=
```

### orWhere()

Add an OR where clause.

```php
User::where('is_admin', true)
    ->orWhere('name', 'Super User')
    ->get();
```

### whereIn()

Match field against array of values.

```php
$userIds = ['uuid1', 'uuid2', 'uuid3'];
User::whereIn('id', $userIds)->get();
```

### whereNull() / whereNotNull()

Check for null values.

```php
// Users without email verification
User::whereNull('email_verified_at')->get();

// Users with email verification
User::whereNotNull('email_verified_at')->get();
```

### orderBy()

Sort results.

```php
// Order by created_at descending
User::orderBy('created_at', 'desc')->get();

// Order by name ascending
User::orderBy('name', 'asc')->get();

// Available fields: id, name, email, created_at, updated_at, last_login_at, is_admin
```

### limit() / take()

Limit number of results.

```php
// Get first 10 users
User::limit(10)->get();

// Alias: take()
User::take(10)->get();
```

### offset() / skip()

Skip a number of results.

```php
// Skip first 20 users
User::offset(20)->limit(10)->get();

// Alias: skip()
User::skip(20)->take(10)->get();
```

### select()

Select specific fields.

```php
// Select specific fields
User::select('id', 'name', 'email')->get();

// Or pass as array
User::select(['id', 'name', 'email'])->get();
```

### Terminal Methods

These methods execute the query and return results:

#### get()

Execute query and return collection.

```php
$users = User::where('is_admin', true)->get();
// Returns: UserCollection
```

#### first()

Get the first result.

```php
$user = User::where('email', 'john@example.com')->first();
// Returns: User|null
```

#### paginate()

Get paginated results.

```php
$paginated = User::where('is_admin', false)->paginate(15);
// Returns: LengthAwarePaginator

// Access pagination data
$users = $paginated->items();
$total = $paginated->total();
$currentPage = $paginated->currentPage();
$lastPage = $paginated->lastPage();

// With custom page
$paginated = User::query()->paginate(15, 2); // Page 2
```

#### count()

Get count of matching records.

```php
$count = User::where('is_admin', true)->count();
// Returns: int
```

#### exists()

Check if any records exist.

```php
$hasAdmins = User::where('is_admin', true)->exists();
// Returns: bool
```

#### toArray()

Get results as array.

```php
$usersArray = User::where('is_admin', true)->toArray();
// Returns: array
```

## Static Methods

### query()

Create a new query builder instance.

```php
$query = User::query();
$query->where('is_admin', true)->get();
```

### Named Scopes

Convenience methods for common queries:

#### admins()

Get all admin users.

```php
$admins = User::admins();
// Returns: UserCollection
```

#### recent()

Get recently active users.

```php
// Default: last 7 days, limit 50
$recentUsers = User::recent();

// Custom parameters
$recentUsers = User::recent(14, 100); // Last 14 days, limit 100
// Returns: UserCollection
```

#### unverified()

Get users with unverified emails.

```php
$unverified = User::unverified();
// Returns: UserCollection
```

#### search()

Search users across name, email, and phone.

```php
$results = User::search('john');
// Returns: UserCollection
```

### CRUD Operations

#### create()

Create a new user via API.

```php
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => 'secret123',
    'is_admin' => false
]);
// Returns: User|null
```

#### updateMany()

Update multiple users at once.

```php
$userIds = ['uuid1', 'uuid2', 'uuid3'];
$updatedCount = User::updateMany($userIds, [
    'is_admin' => true
]);
// Returns: int (number of users updated)
```

#### deleteMany()

Delete multiple users at once.

```php
$userIds = ['uuid1', 'uuid2', 'uuid3'];
$deletedCount = User::deleteMany($userIds);
// Returns: int (number of users deleted)
```

### Utility Methods

#### count()

Get count with optional filters.

```php
// Total users
$total = User::count();

// With filters
$adminCount = User::count(['is_admin' => true]);
```

#### export()

Export users data.

```php
// Export as JSON
$data = User::export('json');

// Export as CSV
$data = User::export('csv');
```

## Instance Methods

Methods available on User instances:

### update()

Update user via API.

```php
$user = User::find('uuid-here');
$user->update([
    'name' => 'Jane Doe',
    'email' => 'jane@example.com'
]);
// Returns: bool
```

### delete()

Delete user via API.

```php
$user = User::find('uuid-here');
$user->delete();
// Returns: bool
```

### save()

Save user (create if new, update if exists).

```php
$user = new User(['name' => 'John', 'email' => 'john@example.com']);
$user->save();
// Returns: bool
```

### refresh()

Refresh user data from API.

```php
$user = User::find('uuid-here');
// ... some time passes ...
$user->refresh(); // Get latest data from API
// Returns: bool
```

### sessions()

Get user's active sessions.

```php
$user = User::find('uuid-here');
$sessions = $user->sessions();
// Returns: array
```

### roles()

Get user's roles (refreshed from API).

```php
$user = User::find('uuid-here');
$roles = $user->roles();
// Returns: array

// Example: [
//     ['id' => '...', 'name' => 'admin', 'service_slug' => 'documents-service'],
//     ['id' => '...', 'name' => 'editor', 'service_slug' => 'media-service']
// ]
```

### metadata()

Get user's service metadata.

```php
$user = User::find('uuid-here');
$metadata = $user->metadata();
// Returns: array
```

## User Collection

The `UserCollection` class extends Laravel's Collection with user-specific methods.

### Filter Methods

```php
$users = User::all();

// Filter by admin status
$admins = $users->admins();
$nonAdmins = $users->nonAdmins();

// Filter by verification status
$verified = $users->verified();
$unverified = $users->unverified();

// Filter by service
$serviceUsers = $users->byService('service-uuid-here');

// Filter by role
$editors = $users->withRole('editor');
$admins = $users->withServiceRole('documents-service', 'admin');
$staff = $users->withAnyRole(['admin', 'moderator', 'editor']);
```

### Data Extraction

```php
$users = User::all();

// Get user IDs
$ids = $users->ids(); // Collection of UUIDs

// Get emails
$emails = $users->emails(); // Collection of emails

// Get names
$names = $users->names(); // Collection of names
```

### Grouping and Sorting

```php
$users = User::all();

// Group by role
$grouped = $users->groupByRole();
// Returns: Collection(['admin' => UserCollection, 'editor' => UserCollection, ...])

// Sort methods
$byName = $users->sortByName(); // ascending
$byNameDesc = $users->sortByName(true); // descending
$byEmail = $users->sortByEmail();
$byLogin = $users->sortByLastLogin();
$byCreated = $users->sortByCreatedAt();
```

### Time-based Filters

```php
$users = User::all();

// Recently active (last 7 days)
$active = $users->recentlyActive(7);

// Recently created (last 30 days)
$new = $users->recentlyCreated(30);
```

### Eager Loading

```php
$users = User::all();

// Load roles for all users
$users->withRoles();
// Adds 'loaded_roles' attribute to each user

// Load sessions for all users
$users->withSessions();
// Adds 'loaded_sessions' attribute to each user

// Chain them
$users->withRoles()->withSessions();
```

### Export and Statistics

```php
$users = User::all();

// Export to CSV
$csv = $users->toCsv(['id', 'name', 'email', 'is_admin']);

// Get statistics
$stats = $users->statistics();
// Returns: [
//     'total' => 150,
//     'admins' => 5,
//     'verified' => 120,
//     'unverified' => 30,
//     'with_last_login' => 100,
//     'roles' => ['admin', 'editor', 'viewer', ...]
// ]
```

## Advanced Examples

### Complex Query with Chaining

```php
use AuthService\Helper\Models\User;

$users = User::where('is_admin', false)
    ->whereNotNull('email_verified_at')
    ->where('created_at', '>', '2024-01-01')
    ->orderBy('last_login_at', 'desc')
    ->limit(50)
    ->get();
```

### Pagination with Filters

```php
$page = request()->get('page', 1);

$users = User::where('is_admin', false)
    ->where('email_verified', true)
    ->orderBy('created_at', 'desc')
    ->paginate(15, $page);

// In Blade:
// @foreach($users as $user)
//     {{ $user->name }}
// @endforeach
//
// {{ $users->links() }}
```

### Search and Filter

```php
$searchTerm = request()->get('q');
$role = request()->get('role');

if ($searchTerm) {
    $users = User::search($searchTerm);

    if ($role) {
        $users = $users->withRole($role);
    }
} else {
    $users = User::all();
}
```

### Bulk Operations

```php
// Get all unverified users
$unverified = User::unverified();

// Extract IDs
$ids = $unverified->ids()->toArray();

// Send verification reminder (example)
foreach ($unverified as $user) {
    // Mail::to($user->email)->send(new VerificationReminder());
}

// Or bulk update them
User::updateMany($ids, [
    'service_metadata' => ['reminder_sent' => true]
]);
```

### Building Dynamic Queries

```php
$query = User::query();

if (request()->has('is_admin')) {
    $query->where('is_admin', request()->boolean('is_admin'));
}

if (request()->has('email')) {
    $query->where('email', 'like', '%' . request()->get('email') . '%');
}

if (request()->has('verified')) {
    if (request()->boolean('verified')) {
        $query->whereNotNull('email_verified_at');
    } else {
        $query->whereNull('email_verified_at');
    }
}

$users = $query->orderBy('created_at', 'desc')->paginate(15);
```

### Working with Relationships

```php
// Get user with sessions
$user = User::find('uuid-here');
$sessions = $user->sessions();

foreach ($sessions as $session) {
    echo "Service: {$session['service']['name']}\n";
    echo "Last Activity: {$session['last_activity_at']}\n";
}

// Get user with roles
$roles = $user->roles();

foreach ($roles as $role) {
    echo "Role: {$role['name']} ({$role['service_slug']})\n";
}
```

### Collection Manipulation

```php
$users = User::all();

// Chain multiple filters
$activeAdmins = $users
    ->admins()
    ->verified()
    ->recentlyActive(30)
    ->sortByLastLogin();

// Get statistics by role
$grouped = $users->groupByRole();

foreach ($grouped as $role => $roleUsers) {
    echo "{$role}: {$roleUsers->count()} users\n";
}

// Get active users by service
$serviceUsers = $users
    ->byService('service-uuid')
    ->recentlyActive(7);
```

## Best Practices

### 1. Use Specific Queries

```php
// Good: Specific query
$admin = User::firstWhere('email', 'admin@example.com');

// Bad: Load all then filter
$admin = User::all()->first(function($user) {
    return $user->email === 'admin@example.com';
});
```

### 2. Limit Results

```php
// Good: Limit at query level
$users = User::where('is_admin', false)->limit(100)->get();

// Bad: Load all then limit
$users = User::all()->take(100);
```

### 3. Use Named Scopes

```php
// Good: Use named scope
$admins = User::admins();

// Less efficient: Manual query
$admins = User::where('is_admin', true)->get();
```

### 4. Handle Errors Gracefully

```php
// Good: Check for null
$user = User::find($uuid);

if (!$user) {
    abort(404, 'User not found');
}

// Or use findOrFail for automatic exception
try {
    $user = User::findOrFail($uuid);
} catch (\RuntimeException $e) {
    abort(404, 'User not found');
}
```

### 5. Use Collections Efficiently

```php
// Good: Use collection methods
$ids = $users->ids()->toArray();

// Bad: Manual loop
$ids = [];
foreach ($users as $user) {
    $ids[] = $user->id;
}
```

### 6. Eager Load Related Data

```php
// Good: Eager load for multiple users
$users = User::all()->withRoles();

foreach ($users as $user) {
    $roles = $user->getAttribute('loaded_roles');
}

// Bad: N+1 query problem
$users = User::all();

foreach ($users as $user) {
    $roles = $user->roles(); // API call for each user
}
```

### 7. Cache Expensive Queries

```php
use Illuminate\Support\Facades\Cache;

// Cache admin users for 1 hour
$admins = Cache::remember('users.admins', 3600, function() {
    return User::admins();
});
```

### 8. Validate Before CRUD Operations

```php
// Good: Validate before create
$validator = Validator::make($request->all(), [
    'name' => 'required|string|max:255',
    'email' => 'required|email|unique:users',
]);

if ($validator->fails()) {
    return back()->withErrors($validator);
}

$user = User::create($request->only(['name', 'email']));

// Bad: Create without validation
$user = User::create($request->all()); // May fail silently
```

## Error Handling

All methods handle errors gracefully and return appropriate defaults:

```php
// Returns null if user not found
$user = User::find('invalid-uuid'); // null

// Returns empty collection if API fails
$users = User::all(); // UserCollection (empty if error)

// Returns 0 if count fails
$count = User::count(); // 0 if error

// Returns false if update fails
$success = $user->update(['name' => 'New Name']); // false if error
```

To check for actual errors, you can catch exceptions or check return values:

```php
try {
    $user = User::findOrFail($uuid);
} catch (\RuntimeException $e) {
    Log::error('User not found', ['uuid' => $uuid]);
    return response()->json(['error' => 'User not found'], 404);
}
```

## API Reference Summary

### Query Builder Methods
- `where($field, $operator, $value)`
- `orWhere($field, $operator, $value)`
- `whereIn($field, $values)`
- `whereNull($field)`
- `whereNotNull($field)`
- `orderBy($field, $direction)`
- `limit($count)` / `take($count)`
- `offset($count)` / `skip($count)`
- `select(...$fields)`
- `get()` → UserCollection
- `first()` → User|null
- `paginate($perPage, $page)` → LengthAwarePaginator
- `count()` → int
- `exists()` → bool
- `toArray()` → array

### Static Methods
- `query()` → UserQueryBuilder
- `find($uuid)` → User|null
- `findOrFail($uuid)` → User
- `all($perPage)` → UserCollection
- `where($field, $operator, $value)` → UserQueryBuilder
- `firstWhere($field, $value)` → User|null
- `admins()` → UserCollection
- `recent($days, $limit)` → UserCollection
- `unverified()` → UserCollection
- `search($term)` → UserCollection
- `count($filters)` → int
- `create($data)` → User|null
- `updateMany($ids, $data)` → int
- `deleteMany($ids)` → int
- `export($format)` → array

### Instance Methods
- `update($data)` → bool
- `delete()` → bool
- `save()` → bool
- `refresh()` → bool
- `sessions()` → array
- `roles()` → array
- `metadata()` → array

### Collection Methods
- `admins()` / `nonAdmins()`
- `verified()` / `unverified()`
- `byService($serviceId)`
- `withRole($role)` / `withServiceRole($slug, $role)` / `withAnyRole($roles)`
- `withRoles()` / `withSessions()`
- `ids()` / `emails()` / `names()`
- `groupByRole()`
- `recentlyActive($days)` / `recentlyCreated($days)`
- `sortByName()` / `sortByEmail()` / `sortByLastLogin()` / `sortByCreatedAt()`
- `toCsv($fields)`
- `statistics()`
