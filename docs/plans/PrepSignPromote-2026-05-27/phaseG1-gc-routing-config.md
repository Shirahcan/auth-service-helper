# Phase G1 — GC command + service-provider mount + config

**Repo:** `auth-service-helper`
**Depends on:** B2, B3, C1, C2, C3

## Goal

1. **Mount the four new routes** from `SharingServiceProvider::boot()`.
2. **Publish config defaults** for `prep.ttl_prepared_hours`, `prep.ttl_signed_hours`, `prep.embed_base_url`, `prep.gc_batch_size`.
3. **Ship `sharing:gc-prep`** artisan command — sweeps GC-eligible rows, fires `PrepResourceExpired` per row, then deletes. Recommended cron: hourly.

## Files

- **Modify:** `src/Sharing/SharingServiceProvider.php` — route mounts + command registration
- **Modify:** `config/authservice-sharing.php` — add `prep` sub-tree
- **Create:** `src/Sharing/Prep/Console/GcPrepCommand.php`
- **Test:** `tests/Feature/Sharing/Prep/GcPrepCommandTest.php`
- **Test:** `tests/Feature/Sharing/Prep/PrepRoutingTest.php`

## Steps

### Step 1 — Add config block

Append to `config/authservice-sharing.php`:

```php
'prep' => [
    /*
    |----------------------------------------------------------------------
    | TTLs for prep_resources
    |----------------------------------------------------------------------
    | Unsigned (prepared) entries die fast — abuse surface. Signed entries
    | live longer — student has committed; give time to complete payment.
    */
    'ttl_prepared_hours' => 24,
    'ttl_signed_hours'   => 72,

    /*
    |----------------------------------------------------------------------
    | Embed base URL
    |----------------------------------------------------------------------
    | Used to construct embed_url returned from /prepare. Defaults to app.url.
    */
    'embed_base_url' => env('SHARING_PREP_EMBED_BASE_URL'),

    /*
    |----------------------------------------------------------------------
    | GC batch size
    |----------------------------------------------------------------------
    | Max rows per sharing:gc-prep sweep. Run hourly via Laravel scheduler.
    */
    'gc_batch_size' => 500,
],
```

### Step 2 — Mount routes in SharingServiceProvider::boot()

Append to the existing route group:

```php
// Prep–Sign–Promote routes (v1.4)
$router->group([
    'prefix' => 'api/v1/sharing/prep',
    'middleware' => ['api', 'share-envelope.verify'],
], function ($router) {
    $router->post('/prepare',                [\AuthService\Helper\Sharing\Prep\Http\Controllers\PrepareController::class, 'prepare']);
    $router->post('/{prep_id}/promote',      [\AuthService\Helper\Sharing\Prep\Http\Controllers\PromoteController::class, 'promote']);
    $router->post('/{prep_id}/status',       [\AuthService\Helper\Sharing\Prep\Http\Controllers\PromoteController::class, 'status']);
});

// Public iframe surface (auth'd by unguessable prep_id only — NOT envelope-signed)
$router->group([
    'prefix' => 'sharing/embed',
    'middleware' => ['api'],
], function ($router) {
    $router->get('/{intent_slug}/{prep_id}',         [\AuthService\Helper\Sharing\Prep\Http\Controllers\EmbedController::class, 'render']);
    $router->post('/{intent_slug}/{prep_id}/submit', [\AuthService\Helper\Sharing\Prep\Http\Controllers\EmbedController::class, 'submit']);
});
```

Append command registration to the `if ($this->app->runningInConsole())` block:

```php
\AuthService\Helper\Sharing\Prep\Console\GcPrepCommand::class,
```

### Step 3 — Implement GC command

```php
<?php
// src/Sharing/Prep/Console/GcPrepCommand.php
namespace AuthService\Helper\Sharing\Prep\Console;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceExpired;
use AuthService\Helper\Sharing\Prep\PrepResourceRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

class GcPrepCommand extends Command
{
    protected $signature = 'sharing:gc-prep
        {--batch=  : Override authservice.sharing.prep.gc_batch_size (max rows per sweep)}
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
            Event::dispatch(new PrepResourceExpired($row));  // listeners can see the row BEFORE deletion
            if (!$dryRun) {
                $repo->delete($row);
            }
        }

        $this->info($dryRun ? 'Dry-run complete; no rows deleted.' : "Deleted {$count} rows.");
        return 0;
    }
}
```

### Step 4 — Failing tests

```php
<?php
// tests/Feature/Sharing/Prep/GcPrepCommandTest.php
namespace Tests\Feature\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceExpired;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

class GcPrepCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations');
    }

    public function test_sweeps_only_expired_non_promoted_rows(): void
    {
        Event::fake([PrepResourceExpired::class]);

        $expired1 = PrepResource::factory()->create(['status' => 'prepared', 'expires_at' => now()->subHour()]);
        $expired2 = PrepResource::factory()->create(['status' => 'signed',   'expires_at' => now()->subHour()]);
        $promoted = PrepResource::factory()->create(['status' => 'promoted', 'expires_at' => now()->subYear(), 'promoted_at' => now()->subYear(), 'permanent_resource_id' => 'p-1']);
        $future   = PrepResource::factory()->create(['status' => 'prepared', 'expires_at' => now()->addHour()]);

        $this->artisan('sharing:gc-prep')->assertExitCode(0);

        $this->assertNull(PrepResource::find($expired1->id), 'expected expired prepared row to be deleted');
        $this->assertNull(PrepResource::find($expired2->id), 'expected expired signed row to be deleted');
        $this->assertNotNull(PrepResource::find($promoted->id), 'promoted rows must NEVER be deleted');
        $this->assertNotNull(PrepResource::find($future->id), 'non-expired rows must stay');

        Event::assertDispatched(PrepResourceExpired::class, 2);
    }

    public function test_dry_run_does_not_delete(): void
    {
        PrepResource::factory()->create(['status' => 'prepared', 'expires_at' => now()->subHour()]);
        $this->artisan('sharing:gc-prep', ['--dry-run' => true])->assertExitCode(0);
        $this->assertEquals(1, PrepResource::count(), 'dry-run must not delete');
    }

    public function test_batch_caps_sweep_size(): void
    {
        for ($i = 0; $i < 5; $i++) {
            PrepResource::factory()->create(['status' => 'prepared', 'expires_at' => now()->subHour()]);
        }
        $this->artisan('sharing:gc-prep', ['--batch' => 2])->assertExitCode(0);
        $this->assertEquals(3, PrepResource::count(), 'should only delete 2 per --batch=2');
    }
}
```

```php
<?php
// tests/Feature/Sharing/Prep/PrepRoutingTest.php
namespace Tests\Feature\Sharing\Prep;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class PrepRoutingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    public function test_prepare_route_registered(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/sharing/prep/prepare' && in_array('POST', $r->methods(), true),
        );
        $this->assertNotNull($route);
        $this->assertContains('share-envelope.verify', $route->gatherMiddleware());
    }

    public function test_promote_status_routes_registered(): void
    {
        $routes = collect(Route::getRoutes());
        $this->assertNotNull($routes->first(fn ($r) => $r->uri() === 'api/v1/sharing/prep/{prep_id}/promote'));
        $this->assertNotNull($routes->first(fn ($r) => $r->uri() === 'api/v1/sharing/prep/{prep_id}/status'));
    }

    public function test_embed_routes_registered_without_envelope_auth(): void
    {
        $render = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'sharing/embed/{intent_slug}/{prep_id}' && in_array('GET', $r->methods(), true),
        );
        $submit = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'sharing/embed/{intent_slug}/{prep_id}/submit' && in_array('POST', $r->methods(), true),
        );
        $this->assertNotNull($render);
        $this->assertNotNull($submit);
        $this->assertNotContains('share-envelope.verify', $render->gatherMiddleware());
        $this->assertNotContains('share-envelope.verify', $submit->gatherMiddleware());
    }
}
```

### Step 5 — Run + commit

```bash
vendor/bin/phpunit tests/Feature/Sharing/Prep/GcPrepCommandTest.php tests/Feature/Sharing/Prep/PrepRoutingTest.php
# Expected: 6 passed total.

git add src/Sharing/SharingServiceProvider.php config/authservice-sharing.php \
        src/Sharing/Prep/Console/GcPrepCommand.php \
        tests/Feature/Sharing/Prep/GcPrepCommandTest.php \
        tests/Feature/Sharing/Prep/PrepRoutingTest.php
git commit -m "feat(sharing): phase G1 — GC command + route mount + config

sharing:gc-prep sweeps expired prepared/signed/expired rows (NEVER
promoted), firing PrepResourceExpired per row before deletion. Routes
mounted under /api/v1/sharing/prep (envelope-signed) and /sharing/embed
(public, prep_id-secured). Config defaults: ttl_prepared_hours=24,
ttl_signed_hours=72, gc_batch_size=500.

Phase: G1 of docs/plans/PrepSignPromote-2026-05-27/"
```

## Verification checklist

- [ ] All four routes registered with the correct middleware (envelope-signed for prepare/promote/status; public for embed/submit)
- [ ] `sharing:gc-prep` skips promoted rows (immortality assertion)
- [ ] `--batch` flag caps sweep size
- [ ] `--dry-run` prints intent without deleting
- [ ] `PrepResourceExpired` fires BEFORE deletion (so listener can grab data)
- [ ] Recommended cron: `* */1 * * * php artisan sharing:gc-prep` (hourly)
