<?php

namespace AuthService\Helper\Auth;

use AuthService\Helper\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Session\Session;

class SessionUserProvider implements UserProvider
{
    /**
     * The session store instance
     */
    protected Session $session;

    /**
     * Create a new session user provider
     */
    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Retrieve a user by their unique identifier
     */
    public function retrieveById($identifier): ?Authenticatable
    {
        $userData = $this->session->get('auth_user');

        if (!$userData || !is_array($userData)) {
            return null;
        }

        $user = User::createFromSession($userData);

        // Check if the ID matches
        if ($user && $user->getAuthIdentifier() == $identifier) {
            return $user;
        }

        return null;
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token
     */
    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        // Not used for session-based authentication
        return null;
    }

    /**
     * Update the "remember me" token for the given user in storage
     */
    public function updateRememberToken(Authenticatable $user, $token): void
    {
        // Not used for session-based authentication
    }

    /**
     * Retrieve a user by the given credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        // Not used for session-based authentication
        // Auth happens via the auth service, not locally
        return null;
    }

    /**
     * Validate a user against the given credentials
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        // Not used for session-based authentication
        // Validation happens via the auth service
        return false;
    }

    /**
     * Rehash the user's password if required and supported
     */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        // Not applicable for session-based authentication
    }
}
