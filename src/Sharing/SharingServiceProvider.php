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
        $this->app->singleton(IntentRegistry::class);

        $this->app->singleton(\AuthService\Helper\Sharing\Client\UserShareClient::class);
        $this->app->singleton(\AuthService\Helper\Sharing\Client\HandoffTokenClient::class);
        $this->app->singleton(\AuthService\Helper\Sharing\SharingService::class);
    }

    public function boot(): void
    {
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
