<?php

namespace AuthService\Helper;

use AuthService\Helper\Auth\SessionGuard;
use AuthService\Helper\Auth\SessionUserProvider;
use AuthService\Helper\Commands\InstallCommand;
use AuthService\Helper\Http\Controllers\AuthController;
use AuthService\Helper\Middleware\Authenticate;
use AuthService\Helper\Middleware\HasRoleMiddleware;
use AuthService\Helper\Middleware\TrustedServiceMiddleware;
use AuthService\Helper\Services\AuthServiceClient;
use AuthService\Helper\View\Components\AccountAvatar;
use AuthService\Helper\View\Components\AccountSwitcher;
use AuthService\Helper\View\Components\AccountSwitcherLoader;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AuthServiceHelperServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/authservice.php',
            'authservice'
        );

        // Register AuthServiceClient as singleton
        $this->app->singleton(AuthServiceClient::class, function ($app) {
            return new AuthServiceClient();
        });

        // Load helper functions
        require_once __DIR__ . '/Helpers/auth_helpers.php';
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/authservice.php' => config_path('authservice.php'),
        ], 'authservice-config');

        // Publish views
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/authservice'),
        ], 'authservice-views');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'authservice');

        // Register Blade components
        Blade::component('authservice-account-switcher-loader', AccountSwitcherLoader::class);
        Blade::component('authservice-account-switcher', AccountSwitcher::class);
        Blade::component('authservice-account-avatar', AccountAvatar::class);

        // Register middleware
        $router = $this->app['router'];
        $router->aliasMiddleware('authservice.auth', Authenticate::class);
        $router->aliasMiddleware('authservice.role', HasRoleMiddleware::class);
        $router->aliasMiddleware('authservice.trusted', TrustedServiceMiddleware::class);

        // Register custom auth guard and provider
        $this->registerAuthSystem();

        // Register routes
        $this->registerRoutes();

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }

    /**
     * Register the custom auth system
     */
    protected function registerAuthSystem(): void
    {
        // Register the custom user provider
        Auth::provider('authservice', function ($app, array $config) {
            return new SessionUserProvider($app['session.store']);
        });

        // Register the custom guard
        Auth::extend('authservice', function ($app, $name, array $config) {
            return new SessionGuard(
                $name,
                Auth::createUserProvider($config['provider']),
                $app['session.store']
            );
        });
    }

    /**
     * Register package routes
     */
    protected function registerRoutes(): void
    {
        Route::group([
            'prefix' => 'auth',
            'as' => 'auth.',
            'middleware' => ['web'],
        ], function () {
            Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
            Route::post('/generate', [AuthController::class, 'generateLanding'])->name('generate');
            Route::get('/callback', [AuthController::class, 'handleCallback'])->name('callback');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

            // Account switcher routes
            Route::get('/session-accounts', [\AuthService\Helper\Http\Controllers\AccountSwitcherController::class, 'getSessionAccounts'])
                ->name('session-accounts');
            Route::post('/switch-account', [\AuthService\Helper\Http\Controllers\AccountSwitcherController::class, 'switchAccount'])
                ->name('switch-account');
            Route::post('/create-add-account-session', [\AuthService\Helper\Http\Controllers\AccountSwitcherController::class, 'createAddAccountSession'])
                ->name('create-add-account-session');
            Route::delete('/remove-account/{uuid}', [\AuthService\Helper\Http\Controllers\AccountSwitcherController::class, 'removeAccount'])
                ->name('remove-account');

            // Iframe widget session sync routes
            Route::post('/sync-session', [\AuthService\Helper\Http\Controllers\AccountSwitcherController::class, 'syncSession'])
                ->name('sync-session');
            Route::post('/sync-token', [\AuthService\Helper\Http\Controllers\AccountSwitcherController::class, 'syncToken'])
                ->name('sync-token');
        });
    }
}
