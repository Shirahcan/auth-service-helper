<?php

namespace AuthService\Helper\Collections;

use AuthService\Helper\Models\User;
use AuthService\Helper\Services\AuthServiceClient;
use Illuminate\Support\Collection;

class UserCollection extends Collection
{
    /**
     * Create a new collection from raw user data
     *
     * @param array $users Array of user data from API
     */
    public function __construct($users = [])
    {
        $items = [];

        foreach ($users as $userData) {
            if ($userData instanceof User) {
                $items[] = $userData;
            } else {
                $items[] = User::createFromSession($userData);
            }
        }

        parent::__construct($items);
    }

    /**
     * Load roles for all users in the collection
     *
     * @return $this
     */
    public function withRoles(): self
    {
        $client = app(AuthServiceClient::class);

        $this->each(function (User $user) use ($client) {
            try {
                $response = $client->getUserRoles($user->id);
                $roles = $response['data']['roles'] ?? [];
                $user->setAttribute('loaded_roles', $roles);
            } catch (\Exception $e) {
                // Silently fail if roles can't be loaded
            }
        });

        return $this;
    }

    /**
     * Load sessions for all users in the collection
     *
     * @return $this
     */
    public function withSessions(): self
    {
        $client = app(AuthServiceClient::class);

        $this->each(function (User $user) use ($client) {
            try {
                $response = $client->getUserSessions($user->id);
                $sessions = $response['data']['sessions'] ?? [];
                $user->setAttribute('loaded_sessions', $sessions);
            } catch (\Exception $e) {
                // Silently fail if sessions can't be loaded
            }
        });

        return $this;
    }

    /**
     * Filter to only admin users
     *
     * @return static
     */
    public function admins(): self
    {
        return $this->filter(function (User $user) {
            return $user->is_admin === true || $user->getAttribute('is_admin') === true;
        });
    }

    /**
     * Filter to only non-admin users
     *
     * @return static
     */
    public function nonAdmins(): self
    {
        return $this->filter(function (User $user) {
            return $user->is_admin !== true && $user->getAttribute('is_admin') !== true;
        });
    }

    /**
     * Filter to only verified users
     *
     * @return static
     */
    public function verified(): self
    {
        return $this->filter(function (User $user) {
            return $user->hasVerifiedEmail();
        });
    }

    /**
     * Filter to only unverified users
     *
     * @return static
     */
    public function unverified(): self
    {
        return $this->filter(function (User $user) {
            return !$user->hasVerifiedEmail();
        });
    }

    /**
     * Filter users by service ID
     *
     * @param string $serviceId Service UUID
     * @return static
     */
    public function byService(string $serviceId): self
    {
        return $this->filter(function (User $user) use ($serviceId) {
            return $user->getAttribute('created_by_service_id') === $serviceId;
        });
    }

    /**
     * Filter users by role
     *
     * @param string $role Role name
     * @return static
     */
    public function withRole(string $role): self
    {
        return $this->filter(function (User $user) use ($role) {
            return $user->hasRole($role);
        });
    }

    /**
     * Filter users by service-scoped role
     *
     * @param string $serviceSlug Service slug
     * @param string $roleName Role name
     * @return static
     */
    public function withServiceRole(string $serviceSlug, string $roleName): self
    {
        return $this->filter(function (User $user) use ($serviceSlug, $roleName) {
            return $user->hasServiceRole($serviceSlug, $roleName);
        });
    }

    /**
     * Filter users by any of the given roles
     *
     * @param array $roles Array of role names
     * @return static
     */
    public function withAnyRole(array $roles): self
    {
        return $this->filter(function (User $user) use ($roles) {
            return $user->hasAnyRole($roles);
        });
    }

    /**
     * Get only user IDs
     *
     * @return Collection
     */
    public function ids(): Collection
    {
        return $this->pluck('id');
    }

    /**
     * Get only user emails
     *
     * @return Collection
     */
    public function emails(): Collection
    {
        return $this->pluck('email');
    }

    /**
     * Get only user names
     *
     * @return Collection
     */
    public function names(): Collection
    {
        return $this->pluck('name');
    }

    /**
     * Group users by role
     *
     * @return Collection
     */
    public function groupByRole(): Collection
    {
        $grouped = [];

        $this->each(function (User $user) use (&$grouped) {
            $roles = $user->getRoles();

            foreach ($roles as $role) {
                if (!isset($grouped[$role])) {
                    $grouped[$role] = [];
                }

                $grouped[$role][] = $user;
            }
        });

        return collect($grouped)->map(function ($users) {
            return new static($users);
        });
    }

    /**
     * Get users who logged in recently
     *
     * @param int $days Number of days
     * @return static
     */
    public function recentlyActive(int $days = 7): self
    {
        $threshold = now()->subDays($days);

        return $this->filter(function (User $user) use ($threshold) {
            $lastLogin = $user->getAttribute('last_login_at');

            if (!$lastLogin) {
                return false;
            }

            return strtotime($lastLogin) >= $threshold->timestamp;
        });
    }

    /**
     * Get users created in the last N days
     *
     * @param int $days Number of days
     * @return static
     */
    public function recentlyCreated(int $days = 7): self
    {
        $threshold = now()->subDays($days);

        return $this->filter(function (User $user) use ($threshold) {
            $createdAt = $user->getAttribute('created_at');

            if (!$createdAt) {
                return false;
            }

            return strtotime($createdAt) >= $threshold->timestamp;
        });
    }

    /**
     * Sort by name
     *
     * @param bool $descending Sort descending
     * @return static
     */
    public function sortByName(bool $descending = false): self
    {
        return $descending
            ? $this->sortByDesc('name')
            : $this->sortBy('name');
    }

    /**
     * Sort by email
     *
     * @param bool $descending Sort descending
     * @return static
     */
    public function sortByEmail(bool $descending = false): self
    {
        return $descending
            ? $this->sortByDesc('email')
            : $this->sortBy('email');
    }

    /**
     * Sort by last login date
     *
     * @param bool $descending Sort descending (most recent first)
     * @return static
     */
    public function sortByLastLogin(bool $descending = true): self
    {
        return $descending
            ? $this->sortByDesc('last_login_at')
            : $this->sortBy('last_login_at');
    }

    /**
     * Sort by created date
     *
     * @param bool $descending Sort descending (newest first)
     * @return static
     */
    public function sortByCreatedAt(bool $descending = true): self
    {
        return $descending
            ? $this->sortByDesc('created_at')
            : $this->sortBy('created_at');
    }

    /**
     * Convert collection to array of arrays (not User objects)
     *
     * @return array
     */
    public function toArrayOfArrays(): array
    {
        return $this->map(function (User $user) {
            return $user->toArray();
        })->all();
    }

    /**
     * Export collection to CSV format
     *
     * @param array $fields Fields to include
     * @return array
     */
    public function toCsv(array $fields = ['id', 'name', 'email', 'is_admin', 'created_at']): array
    {
        $csv = [];

        // Header row
        $csv[] = $fields;

        // Data rows
        $this->each(function (User $user) use (&$csv, $fields) {
            $row = [];

            foreach ($fields as $field) {
                $row[] = $user->getAttribute($field);
            }

            $csv[] = $row;
        });

        return $csv;
    }

    /**
     * Get statistics about the collection
     *
     * @return array
     */
    public function statistics(): array
    {
        return [
            'total' => $this->count(),
            'admins' => $this->admins()->count(),
            'verified' => $this->verified()->count(),
            'unverified' => $this->unverified()->count(),
            'with_last_login' => $this->filter(function (User $user) {
                return $user->getAttribute('last_login_at') !== null;
            })->count(),
            'roles' => $this->flatMap(function (User $user) {
                return $user->getRoles();
            })->unique()->values()->all(),
        ];
    }
}
