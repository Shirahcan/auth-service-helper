# Phase B1 — `Sharing/` namespace scaffolding + ServiceProvider hook

**Repo:** `auth-service-helper`
**Spec section:** §7 "Helper Surface" — namespace layout
**Depends on:** Nothing

## Goal

Lay the directory skeleton for `src/Sharing/` and register a new `SharingServiceProvider` from the existing `AuthServiceHelperServiceProvider`. No business logic yet — just the wiring.

## Files

- **Create:** `src/Sharing/SharingServiceProvider.php`
- **Modify:** `src/AuthServiceHelperServiceProvider.php` (one register line)
- **Modify:** `composer.json` (add `Symfony\Component\Yaml` if not already pulled in — needed by Phase D2c JSON-schema work)
- **Test:** `tests/Unit/Sharing/ServiceProviderRegistrationTest.php`

## Steps

### Step 1 — Write the failing registration test

```php
<?php
// tests/Unit/Sharing/ServiceProviderRegistrationTest.php

namespace Tests\Unit\Sharing;

use AuthService\Helper\Sharing\SharingServiceProvider;
use Orchestra\Testbench\TestCase;

class ServiceProviderRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    public function test_sharing_provider_is_registered(): void
    {
        $providers = collect($this->app->getLoadedProviders())->keys()->all();
        $this->assertContains(SharingServiceProvider::class, $providers);
    }
}
```

### Step 2 — Run, expect failure

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-helper
vendor/bin/pest tests/Unit/Sharing/ServiceProviderRegistrationTest.php
```

Expected: `Class "AuthService\Helper\Sharing\SharingServiceProvider" not found`.

### Step 3 — Create the SharingServiceProvider

```php
<?php
// src/Sharing/SharingServiceProvider.php

namespace AuthService\Helper\Sharing;

use Illuminate\Support\ServiceProvider;

class SharingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bindings added in later phases (B6 IntentRegistry, E2 Outbox repo, etc.)
    }

    public function boot(): void
    {
        // Migrations + routes wired in later phases (D4, E1)
    }
}
```

### Step 4 — Register from parent provider

In `src/AuthServiceHelperServiceProvider.php`, inside the existing `register()` method, add:

```php
$this->app->register(\AuthService\Helper\Sharing\SharingServiceProvider::class);
```

### Step 5 — Add composer dep + run + commit

In `composer.json`, ensure `"symfony/yaml": "^6.0 || ^7.0"` is in `require` (for OpenAPI fixture parsing in Phase G3):

```bash
composer require symfony/yaml --no-update
composer update symfony/yaml

vendor/bin/pest tests/Unit/Sharing/ServiceProviderRegistrationTest.php
# Expected: 1 passed.

git add src/Sharing/SharingServiceProvider.php \
        src/AuthServiceHelperServiceProvider.php \
        composer.json composer.lock \
        tests/Unit/Sharing/ServiceProviderRegistrationTest.php
git commit -m "feat(sharing): phase B1 — SharingServiceProvider scaffold

Empty provider registered from AuthServiceHelperServiceProvider; all
Sharing/ bindings added incrementally in later phases. symfony/yaml
added for OpenAPI fixture work in G3.

Phase: B1 of docs/plans/InterProductCommunication-2026-05-27/"
```
