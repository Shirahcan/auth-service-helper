<?php

namespace AuthService\Helper\Sharing;

use AuthService\Helper\Sharing\Intents\Builtin\DocumentAddedPayload;
use AuthService\Helper\Sharing\Intents\Builtin\InvitePayload;
use AuthService\Helper\Sharing\Intents\Builtin\ProfileSyncPayload;
use AuthService\Helper\Sharing\Intents\Builtin\ReferralPayload;
use AuthService\Helper\Sharing\Intents\Builtin\RevocationNoticePayload;
use AuthService\Helper\Sharing\Intents\Builtin\ServicePurchasePayload;
use AuthService\Helper\Sharing\Intents\Builtin\StatusUpdatePayload;
use AuthService\Helper\Sharing\Intents\IntentRegistry;
use Illuminate\Support\ServiceProvider;

class SharingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Manual merge into authservice.sharing.* — Laravel 12's mergeConfigFrom
        // builds the file path from the dotted key, so it can't be used here.
        $defaults = require __DIR__ . '/../../config/authservice-sharing.php';
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->app['config'];
        $existing = (array) $config->get('authservice.sharing', []);
        $config->set('authservice.sharing', array_merge($defaults, $existing));

        $this->app->singleton(IntentRegistry::class);

        $this->app->singleton(\AuthService\Helper\Sharing\Client\UserShareClient::class);
        $this->app->singleton(\AuthService\Helper\Sharing\Client\HandoffTokenClient::class);
        $this->app->singleton(\AuthService\Helper\Sharing\SharingService::class);

        $this->app->bind(
            \AuthService\Helper\Sharing\Envelope\Contracts\IdempotencyStore::class,
            \AuthService\Helper\Sharing\Envelope\Stores\EloquentIdempotencyStore::class,
        );
    }

    public function boot(): void
    {
        // Migrations
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        // Middleware aliases
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('share-envelope.verify', \AuthService\Helper\Sharing\Inbox\Http\Middleware\VerifyShareEnvelopeSignature::class);
        $router->aliasMiddleware('share-helper.internal', \AuthService\Helper\Sharing\Inbox\Http\Middleware\VerifyInternalToken::class);

        // Routes
        $router->group([
            'prefix' => '',
            'middleware' => ['api'],
        ], function ($router) {
            $router->post(
                ltrim((string) config('authservice.sharing.webhook_path'), '/'),
                [\AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundShareWebhookController::class, 'receive'],
            )->middleware('share-envelope.verify');

            $router->post(
                ltrim((string) config('authservice.sharing.handoff_exchange_path'), '/'),
                [\AuthService\Helper\Sharing\Inbox\Http\Controllers\InboundHandoffExchangeController::class, 'exchange'],
            )->middleware('share-helper.internal');
        });

        // Config publish
        $this->publishes([
            __DIR__ . '/../../config/authservice-sharing.php' => config_path('authservice-sharing.php'),
        ], 'auth-service-helper-sharing-config');

        // Register built-in intents
        $this->app->afterResolving(IntentRegistry::class, function (IntentRegistry $reg) {
            $base = __DIR__ . '/Intents/Builtin/schemas';
            $reg->register('service_purchase', ServicePurchasePayload::class, "{$base}/service-purchase.json");
            $reg->register('profile_sync', ProfileSyncPayload::class, "{$base}/profile-sync.json");
            $reg->register('document_added', DocumentAddedPayload::class, "{$base}/document-added.json");
            $reg->register('status_update', StatusUpdatePayload::class, "{$base}/status-update.json");
            $reg->register('referral', ReferralPayload::class, "{$base}/referral.json");
            $reg->register('invite', InvitePayload::class, "{$base}/invite.json");
            $reg->register('revocation_notice', RevocationNoticePayload::class, "{$base}/revocation-notice.json");
        });
    }
}
