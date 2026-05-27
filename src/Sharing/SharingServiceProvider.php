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

        // Prep–Sign–Promote (v1.4)
        $this->app->singleton(\AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry::class);
        $this->app->singleton(\AuthService\Helper\Sharing\Prep\PrepResourceRepository::class);
        $this->app->singleton(\AuthService\Helper\Sharing\Prep\Client\PrepClient::class);
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

        // Prep–Sign–Promote routes (v1.4)
        $router->group([
            'prefix' => 'api/v1/sharing/prep',
            'middleware' => ['api', 'share-envelope.verify'],
        ], function ($router) {
            $router->post('/prepare',           [\AuthService\Helper\Sharing\Prep\Http\Controllers\PrepareController::class, 'prepare']);
            $router->post('/{prep_id}/promote', [\AuthService\Helper\Sharing\Prep\Http\Controllers\PromoteController::class, 'promote']);
            $router->post('/{prep_id}/status',  [\AuthService\Helper\Sharing\Prep\Http\Controllers\PromoteController::class, 'status']);
        });

        // Public iframe surface (auth'd by unguessable prep_id only — NOT envelope-signed)
        $router->group([
            'prefix' => 'sharing/embed',
            'middleware' => ['api'],
        ], function ($router) {
            $router->get('/{intent_slug}/{prep_id}',         [\AuthService\Helper\Sharing\Prep\Http\Controllers\EmbedController::class, 'render']);
            $router->post('/{intent_slug}/{prep_id}/submit', [\AuthService\Helper\Sharing\Prep\Http\Controllers\EmbedController::class, 'submit']);
        });

        // Config publish
        $this->publishes([
            __DIR__ . '/../../config/authservice-sharing.php' => config_path('authservice-sharing.php'),
        ], 'auth-service-helper-sharing-config');

        // Commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \AuthService\Helper\Sharing\Console\PurgeDeliveredCommand::class,
                \AuthService\Helper\Sharing\Prep\Console\GcPrepCommand::class,
            ]);
        }

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

        // Register built-in Prep–Sign–Promote intent handlers (v1.4)
        $this->app->afterResolving(
            \AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry::class,
            function (\AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry $reg) {
                $reg->register(
                    'agreement_sign',
                    new \AuthService\Helper\Sharing\Prep\Intents\Builtin\AgreementSignHandler(),
                );
            },
        );
    }
}
