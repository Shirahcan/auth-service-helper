<?php

namespace AuthService\Helper\Models;

use ArrayAccess;
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
