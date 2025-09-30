<?php

namespace AuthService\Helper;

use AuthService\Helper\Http\Controllers\AuthController;
use AuthService\Helper\Middleware\HasRoleMiddleware;
use AuthService\Helper\Middleware\TrustedServiceMiddleware;
use AuthService\Helper\Services\AuthServiceClient;
use AuthService\Helper\View\Components\AccountSwitcher;
use AuthService\Helper\View\Components\AccountSwitcherLoader;
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

        // Register middleware
        $router = $this->app['router'];
        $router->aliasMiddleware('authservice.trusted', TrustedServiceMiddleware::class);
        $router->aliasMiddleware('authservice.role', HasRoleMiddleware::class);

        // Register routes
        $this->registerRoutes();
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
        });
    }
}
