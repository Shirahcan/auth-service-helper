<?php

namespace AuthService\Helper\Models;

use ArrayAccess;
use AuthService\Helper\Collections\UserCollection;
use AuthService\Helper\Query\UserQueryBuilder;
use AuthService\Helper\Services\AuthServiceClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

class User implements Authenticatable, ArrayAccess, Arrayable, Jsonable, JsonSerializable
{
    /**
     * User attributes from session
     */
    protected array $attributes = [];

    /**
     * Create a new User instance
     */
    public function __construct(array $attributes = [])
    {
        // Flatten the user data structure from auth service
        if (isset($attributes['user'])) {
            $this->attributes = $attributes['user'];
        } else {
            $this->attributes = $attributes;
        }
    }

    /**
     * Create User from session data
     */
    public static function createFromSession(array $sessionData): ?self
    {
        if (empty($sessionData)) {
            return null;
        }

        return new static($sessionData);
    }

    /**
     * Get AuthServiceClient instance
     */
    protected static function getClient(): AuthServiceClient
    {
        return app(AuthServiceClient::class);
    }

    /**
     * Create a new query builder instance
     */
    public static function query(): UserQueryBuilder
    {
        return new UserQueryBuilder(static::getClient());
    }

    /**
     * Find a user by UUID
     */
    public static function find(string $uuid): ?self
    {
        try {
            $response = static::getClient()->getUserByUuid($uuid);

            if (!($response['success'] ?? false)) {
                return null;
            }

            $userData = $response['data']['user'] ?? $response['data'] ?? null;

            if (!$userData) {
                return null;
            }

            return static::createFromSession($userData);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Find a user by UUID or throw exception
     */
    public static function findOrFail(string $uuid): self
    {
        $user = static::find($uuid);

        if (!$user) {
            throw new \RuntimeException("User with UUID {$uuid} not found");
        }

        return $user;
    }

    /**
     * Get all users (with optional pagination)
     */
    public static function all(int $perPage = 100): UserCollection
    {
        return static::query()->limit($perPage)->get();
    }

    /**
     * Start a where query
     */
    public static function where(string $field, $operatorOrValue, $value = null): UserQueryBuilder
    {
        return static::query()->where($field, $operatorOrValue, $value);
    }

    /**
     * Find first user matching where clause
     */
    public static function firstWhere(string $field, $value): ?self
    {
        return static::where($field, $value)->first();
    }

    /**
     * Get admin users
     */
    public static function admins(): UserCollection
    {
        try {
            $response = static::getClient()->get('users/admins');

            if (!($response['success'] ?? false)) {
                return new UserCollection([]);
            }

            $users = $response['data']['users'] ?? [];

            return new UserCollection($users);
        } catch (\Exception $e) {
            return new UserCollection([]);
        }
    }

    /**
     * Get recently active users
     */
    public static function recent(int $days = 7, int $limit = 50): UserCollection
    {
        try {
            $response = static::getClient()->get('users/recent', [
                'query' => [
                    'days' => $days,
                    'limit' => $limit
                ]
            ]);

            if (!($response['success'] ?? false)) {
                return new UserCollection([]);
            }

            $users = $response['data']['users'] ?? [];

            return new UserCollection($users);
        } catch (\Exception $e) {
            return new UserCollection([]);
        }
    }

    /**
     * Get unverified users
     */
    public static function unverified(): UserCollection
    {
        try {
            $response = static::getClient()->get('users/unverified');

            if (!($response['success'] ?? false)) {
                return new UserCollection([]);
            }

            $users = $response['data']['users'] ?? [];

            return new UserCollection($users);
        } catch (\Exception $e) {
            return new UserCollection([]);
        }
    }

    /**
     * Search users by term
     */
    public static function search(string $term): UserCollection
    {
        try {
            $response = static::getClient()->searchUsers($term);

            if (!($response['success'] ?? false)) {
                return new UserCollection([]);
            }

            $users = $response['data']['users'] ?? [];

            return new UserCollection($users);
        } catch (\Exception $e) {
            return new UserCollection([]);
        }
    }

    /**
     * Get count of users matching filters
     */
    public static function count(array $filters = []): int
    {
        try {
            $response = static::getClient()->get('users/count', [
                'query' => $filters
            ]);

            return $response['data']['count'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Create a new user via API
     */
    public static function create(array $data): ?self
    {
        try {
            $response = static::getClient()->createUser($data);

            if (!($response['success'] ?? false)) {
                return null;
            }

            $userData = $response['data']['user'] ?? $response['data'] ?? null;

            if (!$userData) {
                return null;
            }

            return static::createFromSession($userData);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Update multiple users at once
     */
    public static function updateMany(array $userIds, array $data): int
    {
        try {
            $response = static::getClient()->post('users/bulk-update', [
                'user_ids' => $userIds,
                'data' => $data
            ]);

            return $response['data']['updated_count'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Delete multiple users at once
     */
    public static function deleteMany(array $userIds): int
    {
        try {
            $response = static::getClient()->post('users/bulk-delete', [
                'user_ids' => $userIds
            ]);

            return $response['data']['deleted_count'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Export users data
     */
    public static function export(string $format = 'json'): array
    {
        try {
            $response = static::getClient()->get('users/export', [
                'query' => ['format' => $format]
            ]);

            if (!($response['success'] ?? false)) {
                return [];
            }

            return $response['data'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get the name of the unique identifier for the user
     */
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /**
     * Get the unique identifier for the user
     */
    public function getAuthIdentifier()
    {
        return $this->attributes['id'] ?? null;
    }

    /**
     * Get the password for the user (not used for session-based auth)
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    /**
     * Get the token value for the "remember me" session (not used)
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    /**
     * Set the token value for the "remember me" session (not used)
     */
    public function setRememberToken($value): void
    {
        // Not used for session-based auth
    }

    /**
     * Get the column name for the "remember me" token (not used)
     */
    public function getRememberTokenName(): ?string
    {
        return null;
    }

    /**
     * Get an attribute from the user
     */
    public function getAttribute(string $key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Set an attribute on the user
     */
    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole(string $role): bool
    {
        $roles = $this->getRoles();
        return in_array($role, $roles);
    }

    /**
     * Check if user has a service-scoped role
     */
    public function hasServiceRole(string $serviceSlug, string $roleName): bool
    {
        $roles = $this->getRoles();

        // Check for exact role match
        if (in_array($roleName, $roles)) {
            return true;
        }

        // Check for service-scoped role
        $scopedRoleName = "{$serviceSlug}:{$roleName}";
        if (in_array($scopedRoleName, $roles)) {
            return true;
        }

        // Check for global admin roles
        if (in_array('super-admin', $roles) || in_array('admin', $roles)) {
            return true;
        }

        return false;
    }

    /**
     * Get all user roles
     */
    public function getRoles(): array
    {
        return $this->attributes['roles'] ?? [];
    }

    /**
     * Get user type (super_admin, admin, or user)
     */
    public function getUserType(): string
    {
        if ($this->hasRole('super-admin')) {
            return 'super_admin';
        }

        if ($this->hasRole('service-admin') || $this->hasRole('admin')) {
            return 'admin';
        }

        return 'user';
    }

    /**
     * Check if user can manage a service
     */
    public function canManageService($serviceId): bool
    {
        if ($this->hasRole('super-admin')) {
            return true;
        }

        if ($this->hasRole('service-admin')) {
            $permissions = $this->attributes['admin_service_permissions'] ?? [];
            return in_array($serviceId, $permissions);
        }

        return false;
    }

    /**
     * Check if user is auth service admin
     */
    public function isAuthServiceAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    /**
     * Check if user has any of the given roles
     */
    public function hasAnyRole(array $roles): bool
    {
        $userRoles = $this->getRoles();
        return !empty(array_intersect($roles, $userRoles));
    }

    /**
     * Check if user has all of the given roles
     */
    public function hasAllRoles(array $roles): bool
    {
        $userRoles = $this->getRoles();
        foreach ($roles as $role) {
            if (!in_array($role, $userRoles)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get service metadata
     */
    public function getServiceMetadata(?string $key = null, $default = null)
    {
        $metadata = $this->attributes['service_metadata'] ?? [];

        if ($key === null) {
            return $metadata;
        }

        return $metadata[$key] ?? $default;
    }

    /**
     * Check if user's email is verified
     */
    public function hasVerifiedEmail(): bool
    {
        return !empty($this->attributes['email_verified_at']);
    }

    /**
     * Update user via API
     */
    public function update(array $data): bool
    {
        $uuid = $this->getAuthIdentifier();

        if (!$uuid) {
            return false;
        }

        try {
            $response = static::getClient()->updateUser($uuid, $data);

            if (!($response['success'] ?? false)) {
                return false;
            }

            // Update local attributes
            $userData = $response['data']['user'] ?? $response['data'] ?? null;

            if ($userData) {
                foreach ($userData as $key => $value) {
                    $this->setAttribute($key, $value);
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Delete user via API
     */
    public function delete(): bool
    {
        $uuid = $this->getAuthIdentifier();

        if (!$uuid) {
            return false;
        }

        try {
            $response = static::getClient()->deleteUser($uuid);
            return $response['success'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get user's sessions from API
     */
    public function sessions(): array
    {
        $uuid = $this->getAuthIdentifier();

        if (!$uuid) {
            return [];
        }

        try {
            $response = static::getClient()->getUserSessions($uuid);

            if (!($response['success'] ?? false)) {
                return [];
            }

            return $response['data']['sessions'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get user's roles from API (refreshed from server)
     */
    public function roles(): array
    {
        $uuid = $this->getAuthIdentifier();

        if (!$uuid) {
            return $this->getRoles();
        }

        try {
            $response = static::getClient()->getUserRoles($uuid);

            if (!($response['success'] ?? false)) {
                return $this->getRoles();
            }

            $roles = $response['data']['roles'] ?? [];

            // Update local roles cache
            $this->setAttribute('loaded_roles', $roles);

            return $roles;
        } catch (\Exception $e) {
            return $this->getRoles();
        }
    }

    /**
     * Get user's metadata from API
     */
    public function metadata(): array
    {
        $uuid = $this->getAuthIdentifier();

        if (!$uuid) {
            return $this->getServiceMetadata() ?? [];
        }

        try {
            $response = static::getClient()->get("users/{$uuid}/metadata");

            if (!($response['success'] ?? false)) {
                return $this->getServiceMetadata() ?? [];
            }

            return $response['data']['metadata'] ?? [];
        } catch (\Exception $e) {
            return $this->getServiceMetadata() ?? [];
        }
    }

    /**
     * Refresh user data from API
     */
    public function refresh(): bool
    {
        $uuid = $this->getAuthIdentifier();

        if (!$uuid) {
            return false;
        }

        try {
            $response = static::getClient()->getUserByUuid($uuid);

            if (!($response['success'] ?? false)) {
                return false;
            }

            $userData = $response['data']['user'] ?? $response['data'] ?? null;

            if (!$userData) {
                return false;
            }

            // Replace all attributes with fresh data
            $this->attributes = $userData;

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Save user data to API (create if new, update if exists)
     */
    public function save(): bool
    {
        $uuid = $this->getAuthIdentifier();

        if ($uuid) {
            // Update existing user
            return $this->update($this->attributes);
        } else {
            // Create new user
            $user = static::create($this->attributes);

            if ($user) {
                $this->attributes = $user->attributes;
                return true;
            }

            return false;
        }
    }

    /**
     * Convert the user to an array
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * Convert the user to JSON
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->attributes, $options);
    }

    /**
     * Specify data which should be serialized to JSON
     */
    public function jsonSerialize(): array
    {
        return $this->attributes;
    }

    /**
     * Magic method to get attributes
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Magic method to set attributes
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Magic method to check if attribute exists
     */
    public function __isset($key)
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Magic method to unset attribute
     */
    public function __unset($key)
    {
        unset($this->attributes[$key]);
    }

    /**
     * ArrayAccess: Check if offset exists
     */
    public function offsetExists($offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    /**
     * ArrayAccess: Get offset value
     */
    public function offsetGet($offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    /**
     * ArrayAccess: Set offset value
     */
    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->attributes[] = $value;
        } else {
            $this->attributes[$offset] = $value;
        }
    }

    /**
     * ArrayAccess: Unset offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->attributes[$offset]);
    }

    /**
     * Convert to string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}
