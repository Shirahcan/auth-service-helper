# Phase G2 — Playwright 2-tab E2E harness

**Repo:** `auth-service-nextjs`
**Spec section:** §14 (Next row) + §8
**Depends on:** F1, F2, F3, F4, F5, H2 (starter app)

## Goal

End-to-end Playwright test that boots TWO Next apps from the starter-app harness (source = "studendly-stub", destination = "portify-stub"), opens both in browser tabs, and proves the seamless redirect: source "Continue to Portify" CTA → 302 → destination `/auth/handoff?token=…&next=/cases/abc` → token exchange → bridge to `/auth/handoff/landing` → `multiAccountAutoAdd` → final `/cases/abc?_toast=…`. Assertions cover the destination session cookie, the account-switcher state, the toast, and the final URL.

## Files

- **Create:** `tests/e2e/handoff-2-tab.spec.ts`
- **Create:** `tests/e2e/helpers/mockBackends.ts`
- **Modify:** `playwright.config.ts` (add second `webServer` entry + `testDir`)
- **Modify:** `package.json` (add `test:e2e` script, `@playwright/test` to `devDependencies` if absent)

## Steps

### Step 1 — Update `playwright.config.ts`

```ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 60_000,
  fullyParallel: false, // shared cookie domain assumptions
  use: {
    trace: 'on-first-retry',
    headless: true,
  },
  webServer: [
    {
      // Studendly-stub (source) — see H2 starter app
      command: 'npm run start:source',
      port: 8101,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
    {
      // Portify-stub (destination)
      command: 'npm run start:destination',
      port: 8102,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
  ],
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
```

Both ports are >= 8100 per project rules.

### Step 2 — Write the mock-backends helper

The two stub Next apps each hit their own PHP backend; for the E2E we intercept those backend calls at the Playwright `page.route` layer so the test is hermetic.

```ts
// tests/e2e/helpers/mockBackends.ts
import type { Page } from '@playwright/test';

const HANDOFF_TOKEN = 'tok_e2e_' + 'a'.repeat(40);
const TARGET_USER = {
  uuid: '66666666-6666-6666-6666-666666666666',
  name: 'Jane Student',
  email: 'jane@example.test',
};
const SESSION_TOKEN = 'sess_e2e_' + 'b'.repeat(40);

export async function mockSourceBackend(page: Page) {
  await page.route('**/api/sharing/mint-handoff*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        token: HANDOFF_TOKEN,
        redirect_url:
          'http://localhost:8102/auth/handoff?token=' + HANDOFF_TOKEN + '&next=/cases/abc',
        expires_at: new Date(Date.now() + 60_000).toISOString(),
      }),
    });
  });
}

export async function mockDestinationBackend(page: Page) {
  await page.route('**/api/v1/inbound/handoff/exchange*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        user: TARGET_USER,
        session_token: SESSION_TOKEN,
        share_id: '55555555-5555-5555-5555-555555555555',
        next_path: '/cases/abc',
      }),
    });
  });

  // The iframe SESSION_CHANGED postMessage is sent by the auth-service iframe;
  // in test mode the destination stub renders a minimal mock iframe that
  // immediately replies SESSION_CHANGED with the appended account. (Provided
  // by the H2 destination starter app.)
}

export const fixtures = { HANDOFF_TOKEN, TARGET_USER, SESSION_TOKEN };
```

### Step 3 — Write the 2-tab spec

```ts
// tests/e2e/handoff-2-tab.spec.ts
import { test, expect } from '@playwright/test';
import { mockSourceBackend, mockDestinationBackend, fixtures } from './helpers/mockBackends';

const SOURCE = 'http://localhost:8101';
const DESTINATION = 'http://localhost:8102';

test('source → destination handoff lands user on /cases/abc with toast', async ({ browser }) => {
  const context = await browser.newContext();

  // Tab 1: source — user "checks out" and clicks the CTA.
  const sourceTab = await context.newPage();
  await mockSourceBackend(sourceTab);
  await sourceTab.goto(SOURCE + '/checkout/done');

  // Capture the redirect URL the CTA produces.
  const [redirectResponse] = await Promise.all([
    sourceTab.waitForResponse((r) => r.status() === 302 || r.url().includes('/auth/handoff')),
    sourceTab.click('[data-testid="continue-to-portify"]'),
  ]);
  const handoffUrl = redirectResponse.url();
  expect(handoffUrl).toContain('/auth/handoff?token=');
  expect(handoffUrl).toContain('next=%2Fcases%2Fabc');

  // Tab 2: destination — open the handoff URL.
  const destTab = await context.newPage();
  await mockDestinationBackend(destTab);
  await destTab.goto(handoffUrl);

  // Wait for the landing → final navigation.
  await destTab.waitForURL(/\/cases\/abc(\?.*)?$/, { timeout: 10_000 });

  // (a) Final URL carries the toast param.
  expect(destTab.url()).toMatch(/_toast=Now%20viewing%20as%20Jane%20Student/);

  // (b) Toast renders.
  await expect(destTab.locator('[data-testid="handoff-arrival-toast"]')).toHaveText(
    /Now viewing as Jane Student/,
  );

  // (c) Session cookie present on destination domain.
  const cookies = await context.cookies(DESTINATION);
  const session = cookies.find((c) => c.name === 'user_id' || c.name === 'session');
  expect(session, 'destination session cookie should be set').toBeDefined();
  expect(session!.value.length).toBeGreaterThan(0);

  // (d) Account appears in switcher.
  await destTab.click('[data-testid="account-switcher-trigger"]');
  await expect(
    destTab.locator(`[data-testid="account-option-${fixtures.TARGET_USER.uuid}"]`),
  ).toBeVisible();

  await context.close();
});

test('invalid token redirects to login with error param', async ({ browser }) => {
  const context = await browser.newContext();
  const destTab = await context.newPage();

  await destTab.route('**/api/v1/inbound/handoff/exchange*', (route) =>
    route.fulfill({ status: 410, contentType: 'application/json', body: '{"reason":"expired"}' }),
  );

  await destTab.goto(DESTINATION + '/auth/handoff?token=bad&next=/cases/abc');
  await destTab.waitForURL(/\/login\?error=handoff_expired/);

  await context.close();
});
```

### Step 4 — `package.json` scripts

Add (do not duplicate existing entries):

```json
{
  "scripts": {
    "test:e2e": "playwright test",
    "start:source": "next start -p 8101 --hostname 0.0.0.0",
    "start:destination": "next start -p 8102 --hostname 0.0.0.0"
  },
  "devDependencies": {
    "@playwright/test": "^1.50.0"
  }
}
```

`start:source` and `start:destination` assume the H2 starter app reads `APP_ROLE=source|destination` from env at boot to choose which page tree to mount. If H2 instead builds two separate Next projects, adjust the commands to `cd starter/source && next start -p 8101` style.

### Step 5 — Install browsers + run + commit

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-nextjs
npm install --save-dev @playwright/test
npx playwright install  # not just `chromium` — see memory: headless-shell variant required
npm run test:e2e
# Expected: 2 passed.

git add playwright.config.ts package.json package-lock.json tests/e2e/
git commit -m "feat(sharing): phase G2 — Playwright 2-tab handoff E2E

Two webServer entries boot the source + destination starter apps on
ports 8101/8102. The spec exercises Tab 1 → CTA → redirect → Tab 2
handoff → landing bridge → final destination URL, asserting the
session cookie, account-switcher state, toast text, and the next
path. Second test covers the 410-expired error path.

Phase: G2 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Notes for executor

- Per project memory (`feedback_playwright_headless_shell`): run `npx playwright install` (full) NOT `chromium` alone — the headless-shell variant is required since Playwright 1.50, or the test will finish in seconds with no DOM matches.
- If the destination's iframe `SESSION_CHANGED` postMessage cannot be cleanly mocked from Playwright, have the H2 destination starter app expose a `?e2e=1` query that swaps the iframe for an inline mock that completes the protocol synchronously. Document this in H2.
- Browser-testing rule (CLAUDE.md): the test is hermetic — it boots its own servers on 8101/8102 and does NOT touch the always-running 8050/8051 dev servers.
