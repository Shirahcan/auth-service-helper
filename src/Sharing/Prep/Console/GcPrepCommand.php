<?php

namespace AuthService\Helper\Sharing\Prep\Console;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceExpired;
use AuthService\Helper\Sharing\Prep\PrepResourceRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

class GcPrepCommand extends Command
{
    protected $signature = 'sharing:gc-prep
        {--batch= : Override authservice.sharing.prep.gc_batch_size (max rows per sweep)}
        {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Sweep expired prep_resources rows (prepared|signed|expired with expires_at < now). Never touches promoted rows.';

    public function handle(PrepResourceRepository $repo): int
    {
        $batch = (int) ($this->option('batch') ?: config('authservice.sharing.prep.gc_batch_size', 500));
        $dryRun = (bool) $this->option('dry-run');

        $rows = $repo->findExpired(limit: $batch);
        $count = $rows->count();

        if ($count === 0) {
            $this->info('No expired prep_resources to sweep.');
            return 0;
        }

        $this->info("Sweeping {$count} expired prep_resources (batch={$batch}, dry-run=" . ($dryRun ? 'yes' : 'no') . ').');

        foreach ($rows as $row) {
            Event::dispatch(new PrepResourceExpired($row));
            if (!$dryRun) {
                $repo->delete($row);
            }
        }

        $this->info($dryRun ? 'Dry-run complete; no rows deleted.' : "Deleted {$count} rows.");
        return 0;
    }
}
