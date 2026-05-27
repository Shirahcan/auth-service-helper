<?php

namespace AuthService\Helper\Sharing\Console;

use AuthService\Helper\Sharing\Outbox\OutboundShareMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PurgeDeliveredCommand extends Command
{
    protected $signature = 'sharing:purge-delivered
        {--before= : ISO date; rows delivered before this date are removed (default: 30 days ago)}';

    protected $description = 'Remove delivered outbound share messages older than the cutoff date.';

    public function handle(): int
    {
        $beforeArg = $this->option('before');

        try {
            $cutoff = $beforeArg
                ? Carbon::parse($beforeArg)
                : Carbon::now()->subDays(30);
        } catch (\Throwable $e) {
            $this->error("Invalid --before value: {$beforeArg}");
            return 1;
        }

        $deleted = OutboundShareMessage::query()
            ->where('status', OutboundShareMessage::STATUS_DELIVERED)
            ->where('delivered_at', '<', $cutoff)
            ->delete();

        $this->info("Purged {$deleted} delivered outbound share messages (cutoff {$cutoff->toIso8601String()}).");
        return 0;
    }
}
