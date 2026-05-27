# Phase B3 — Prep lifecycle events

**Repo:** `auth-service-helper`
**Depends on:** B1

## Goal

Four Laravel events product code listens to for the lifecycle of a prep row. All use `Dispatchable + SerializesModels` so listeners may opt into `ShouldQueue`.

## Files

- **Create:** `src/Sharing/Prep/Events/PrepResourceCreated.php`
- **Create:** `src/Sharing/Prep/Events/PrepResourceSigned.php`
- **Create:** `src/Sharing/Prep/Events/PrepResourcePromoted.php`
- **Create:** `src/Sharing/Prep/Events/PrepResourceExpired.php`
- **Test:** `tests/Unit/Sharing/Prep/PrepResourceEventsTest.php`

## Steps

### Step 1 — Failing test

```php
<?php
// tests/Unit/Sharing/Prep/PrepResourceEventsTest.php
namespace Tests\Unit\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\Events\PrepResourceCreated;
use AuthService\Helper\Sharing\Prep\Events\PrepResourceExpired;
use AuthService\Helper\Sharing\Prep\Events\PrepResourcePromoted;
use AuthService\Helper\Sharing\Prep\Events\PrepResourceSigned;
use AuthService\Helper\Sharing\Prep\PrepResource;
use Orchestra\Testbench\TestCase;

class PrepResourceEventsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    public function test_each_event_exposes_the_prep_resource(): void
    {
        $row = new PrepResource(['id' => 'p-1', 'intent' => 'agreement_sign']);

        $this->assertSame($row, (new PrepResourceCreated($row))->prep);
        $this->assertSame($row, (new PrepResourceSigned($row))->prep);
        $this->assertSame($row, (new PrepResourcePromoted($row, 'perm-1'))->prep);
        $this->assertEquals('perm-1', (new PrepResourcePromoted($row, 'perm-1'))->permanentResourceId);
        $this->assertSame($row, (new PrepResourceExpired($row))->prep);
    }

    public function test_all_events_use_dispatchable_and_serializes_models(): void
    {
        foreach ([
            PrepResourceCreated::class,
            PrepResourceSigned::class,
            PrepResourcePromoted::class,
            PrepResourceExpired::class,
        ] as $class) {
            $traits = class_uses($class);
            $this->assertContains(\Illuminate\Foundation\Events\Dispatchable::class, $traits, "{$class} missing Dispatchable");
            $this->assertContains(\Illuminate\Queue\SerializesModels::class, $traits, "{$class} missing SerializesModels");
        }
    }
}
```

### Step 2 — Implement

```php
<?php
// src/Sharing/Prep/Events/PrepResourceCreated.php
namespace AuthService\Helper\Sharing\Prep\Events;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fires AFTER /prepare creates a NEW prep row (not on idempotent return of existing). */
class PrepResourceCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly PrepResource $prep) {}
}
```

```php
<?php
// src/Sharing/Prep/Events/PrepResourceSigned.php
namespace AuthService\Helper\Sharing\Prep\Events;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fires AFTER /embed/.../submit captures signed_data + flips to signed. */
class PrepResourceSigned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly PrepResource $prep) {}
}
```

```php
<?php
// src/Sharing/Prep/Events/PrepResourcePromoted.php
namespace AuthService\Helper\Sharing\Prep\Events;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fires AFTER /promote creates the permanent record. */
class PrepResourcePromoted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PrepResource $prep,
        public readonly string $permanentResourceId,
    ) {}
}
```

```php
<?php
// src/Sharing/Prep/Events/PrepResourceExpired.php
namespace AuthService\Helper\Sharing\Prep\Events;

use AuthService\Helper\Sharing\Prep\PrepResource;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fires from sharing:gc-prep BEFORE the row is deleted. */
class PrepResourceExpired
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly PrepResource $prep) {}
}
```

### Step 3 — Run + commit

```bash
vendor/bin/phpunit tests/Unit/Sharing/Prep/PrepResourceEventsTest.php
# Expected: 2 passed.

git add src/Sharing/Prep/Events/ tests/Unit/Sharing/Prep/PrepResourceEventsTest.php
git commit -m "feat(sharing): phase B3 — prep lifecycle events

Four events product code listens to for the lifecycle of a prep_resource:
Created (pre-warm caches), Signed (send acknowledgement), Promoted (kick
off case creation), Expired (abandoned-cart reminder). All use
Dispatchable + SerializesModels so listeners may opt into ShouldQueue.

Phase: B3 of docs/plans/PrepSignPromote-2026-05-27/"
```

## Verification checklist

- [ ] PrepResourcePromoted carries BOTH the prep + the permanent_resource_id
- [ ] All four events serialize correctly (SerializesModels)
- [ ] Created fires ONLY on new row insert, not idempotent return (enforced in C1)
- [ ] Expired fires from GC BEFORE the row is deleted (enforced in G1)
