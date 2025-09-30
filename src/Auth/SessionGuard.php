<?php

namespace AuthService\Helper\Auth;

use AuthService\Helper\Models\User;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Auth\Authenticatable;

class SessionGuard implements Guard
{
    /**
     * The name of the guard
     */
    protected string $name;

    /**
     * The user provider implementation
     */
    protected UserProvider $provider;

    /**
     * The session store instance
     */
    protected Session $session;

    /**
     * The currently authenticated user
     */
    protected ?User $user = null;

    /**
     * Indicates if the user was authenticated via a recaller cookie
     */
    protected bool $viaRemember = false;

    /**
     * Create a new authentication guard
     */
    public function __construct(string $name, UserProvider $provider, Session $session)
    {
        $this->name = $name;
        $this->provider = $provider;
        $this->session = $session;
    }

    /**
     * Determine if the current user is authenticated
     */
    public function check(): bool
    {
        return !is_null($this->user());
    }

    /**
     * Determine if the current user is a guest
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * Get the currently authenticated user
     */
    public function user(): ?Authenticatable
    {
        if (!is_null($this->user)) {
            return $this->user;
        }

        // Get user data from session
        $userData = $this->session->get('auth_user');

        if ($userData && is_array($userData)) {
            $this->user = User::createFromSession($userData);
        }

        return $this->user;
    }

    /**
     * Get the ID for the currently authenticated user
     */
    public function id()
    {
        if ($this->user()) {
            return $this->user()->getAuthIdentifier();
        }

        return null;
    }

    /**
     * Validate a user's credentials
     */
    public function validate(array $credentials = []): bool
    {
        // Not used for session-based auth
        return false;
    }

    /**
     * Determine if the guard has a user instance
     */
    public function hasUser(): bool
    {
        return !is_null($this->user);
    }

    /**
     * Set the current user
     */
    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Log a user into the application
     */
    public function login(Authenticatable $user, bool $remember = false): void
    {
        // Set the user instance
        $this->user = $user;

        // Store user data in session
        $this->session->put('auth_user', $user->toArray());
        $this->session->migrate(true);
    }

    /**
     * Log the user out of the application
     */
    public function logout(): void
    {
        // Clear the user instance
        $this->user = null;

        // Remove auth data from session
        $this->session->forget('auth_user');
        $this->session->forget('auth_token');
        $this->session->forget('login_time');
        $this->session->forget('last_activity');
    }

    /**
     * Get the session store instance
     */
    public function getSession(): Session
    {
        return $this->session;
    }

    /**
     * Get the user provider instance
     */
    public function getProvider(): UserProvider
    {
        return $this->provider;
    }

    /**
     * Set the user provider instance
     */
    public function setProvider(UserProvider $provider): void
    {
        $this->provider = $provider;
    }

    /**
     * Get the guard name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Determine if the user was authenticated via "remember me" cookie
     */
    public function viaRemember(): bool
    {
        return $this->viaRemember;
    }
}
