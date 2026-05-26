# Phase H2 — Next helper README + starter app handoff integration

**Repo:** `auth-service-nextjs`
**Spec section:** §8 "Next Helper Surface" — consumer-facing documentation + worked example
**Depends on:** Entire Phase F (the surface being documented must exist) + Phase G2 (Playwright harness exercises the same starter-app routes added here)

## Goal

1. Add a new top-level `## Cross-Product Handoff` section to the Next
   helper's `README.md` that walks a destination-app author through
   wiring up `/auth/handoff` end-to-end.
2. Update the starter app at `test-app/` so that running it gives a real,
   browser-testable destination-side handoff flow (the Playwright suite
   from G2 drives this exact app).

The README snippets MUST match the starter-app code byte-for-byte —
copy from the starter, paste into the README, never invent.

## Files

- **Modify:** `README.md` — add new top-level "Cross-Product Handoff" section.
- **Create:** `test-app/app/auth/handoff/route.ts` — the destination-side App Router handler that calls `consumeHandoffToken`.
- **Create:** `test-app/app/auth/handoff/landing/page.tsx` — the client-side landing page that triggers `multiAccountAutoAdd` and shows the toast.
- **Modify:** `test-app/.env.local.example` — append the new envvars (`NEXT_PUBLIC_AUTH_SERVICE_HANDOFF_PATH`, etc.) and rotate one comment block to mention cross-product handoff.

Note on path: the starter app uses `app/` (not `src/app/`) — confirmed via
`ls test-app/`. Use the `app/` layout exactly; do NOT introduce a `src/`
prefix.

## Steps

### Step 1 — Add the README section

In `README.md`, after the existing "Account Switcher" section (or wherever
the current README places its last top-level capability section), insert
`## Cross-Product Handoff` with these subsections:

1. **Concept** — 2 paragraphs:
   - Source product mints a single-use ~60s token (PHP helper side; link
     out to `auth-service-helper/docs/Sharing_Usage.md#mintHandoffToken`).
   - Destination product consumes the token at `/auth/handoff?token=...&next=...`,
     auto-adds the resolved user as a second account in the existing
     switcher, and lands them on `?next=`.
2. **Install** — single fenced bash block:
   ```bash
   npm install @benbraide/auth-service-nextjs@latest
   ```
3. **Destination-side setup** — three numbered sub-steps:
   - Step 1: Add `app/auth/handoff/route.ts` — paste the contents
     verbatim from the starter app file you create in Step 2 below.
   - Step 2: Add `app/auth/handoff/landing/page.tsx` — paste the contents
     verbatim from the starter app file you create in Step 3 below.
   - Step 3: Set the required environment variables:
     ```bash
     NEXT_PUBLIC_BACKEND_URL=https://api.your-destination.com
     NEXT_SERVICE_API_KEY=<destination service api key>
     ```
     Reference the existing `.env.local.example` for the canonical list.
4. **Multi-account UX** — 1 paragraph explaining that the handoff lands
   the new user as a *second* account in the switcher (NOT a replacement),
   that the existing logged-in user is preserved, and that the switcher
   chip flashes a "Now viewing as <name>" toast. Point at `useHandoffArrival`
   if a product wants to customise the toast.
5. **Troubleshooting** — bulleted list pulling from the typed-error
   responses in Phase F1 (`handoff_expired`, `handoff_mismatch`,
   `handoff_unknown`) — what each redirect query-string error means and
   the most likely root cause.

Target length: ~120 lines added to `README.md`.

### Step 2 — Create `test-app/app/auth/handoff/route.ts`

```ts
// test-app/app/auth/handoff/route.ts
import { consumeHandoffToken } from '@benbraide/auth-service-nextjs/sharing';

export async function GET(req: Request) {
  const url = new URL(req.url);
  const token = url.searchParams.get('token');
  const next = url.searchParams.get('next') ?? '/';

  if (!token) {
    return Response.redirect(new URL('/login?error=handoff_missing_token', url), 302);
  }

  const result = await consumeHandoffToken({
    token,
    backendUrl: process.env.NEXT_PUBLIC_BACKEND_URL!,
    serviceApiKey: process.env.NEXT_SERVICE_API_KEY!,
  });

  if (!result.ok) {
    return Response.redirect(
      new URL(`/login?error=handoff_${result.reason}`, url),
      302,
    );
  }

  const bridge = new URL('/auth/handoff/landing', url);
  bridge.searchParams.set('account_uuid', result.user.uuid);
  bridge.searchParams.set('session_token', result.sessionToken);
  bridge.searchParams.set('next', next);
  bridge.searchParams.set('toast', `Now viewing as ${result.user.name}`);
  return Response.redirect(bridge, 302);
}
```

Confirm `consumeHandoffToken` exports from
`@benbraide/auth-service-nextjs/sharing` (the entry point added in Phase F1).
If F1 instead exports from the package root, update the import path here
AND in the README snippet to match.

### Step 3 — Create `test-app/app/auth/handoff/landing/page.tsx`

```tsx
// test-app/app/auth/handoff/landing/page.tsx
'use client';

import { useEffect } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import { useAccountSwitcher } from '@benbraide/auth-service-nextjs';
import { multiAccountAutoAdd } from '@benbraide/auth-service-nextjs/sharing';

export default function HandoffLanding() {
  const params = useSearchParams();
  const router = useRouter();
  const { addAccount, switchAccount } = useAccountSwitcher();

  useEffect(() => {
    (async () => {
      const accountUuid = params.get('account_uuid');
      const sessionToken = params.get('session_token');
      if (!accountUuid || !sessionToken) {
        router.replace('/login?error=handoff_landing_missing_params');
        return;
      }

      await multiAccountAutoAdd({
        accountUuid,
        sessionToken,
        switcher: { addAccount, switchAccount },
      });

      const next = params.get('next') ?? '/';
      const toast = params.get('toast') ?? '';
      router.replace(
        `${next}${toast ? `${next.includes('?') ? '&' : '?'}_toast=${encodeURIComponent(toast)}` : ''}`,
      );
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="min-h-screen flex items-center justify-center p-4">
      <p className="text-sm text-slate-500">Setting up your account…</p>
    </div>
  );
}
```

Confirm `useAccountSwitcher` and `multiAccountAutoAdd` come from the
correct subpaths exported by Phase F. If the existing test-app already
imports `useAccountSwitcher` from a different path, mirror that import
style for consistency.

### Step 4 — Update `test-app/.env.local.example`

Append the following block at the bottom of the existing file (preserve
all current entries):

```
# ========================================
# Cross-Product Handoff (Phase H2)
# ========================================

# Path on auth-service for the handoff exchange (default below works for
# most deployments — override only if your auth-service mounts the route
# under a non-standard prefix)
NEXT_PUBLIC_AUTH_SERVICE_HANDOFF_PATH=/api/v1/auth/handoff-tokens

# (Optional) Slug used in toast messages; falls back to the resolved
# user's name when unset.
NEXT_PUBLIC_PRODUCT_DISPLAY_NAME=Test App
```

Do NOT modify the existing entries (the test-app already has
`NEXT_PUBLIC_BACKEND_URL`, `NEXT_SERVICE_API_KEY`, `NEXT_SERVICE_SLUG`
which are reused for handoff).

### Step 5 — Verify the starter app boots, then commit

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-nextjs\test-app

# Verify the helper package is linked / resolvable (do NOT npm install
# the published version if you're testing against local dist; use the
# existing test-app linkage)
npx tsc --noEmit

# Spot-check the new routes exist
test -f app/auth/handoff/route.ts && test -f app/auth/handoff/landing/page.tsx && echo OK

cd ..
git add README.md \
        test-app/app/auth/handoff/route.ts \
        test-app/app/auth/handoff/landing/page.tsx \
        test-app/.env.local.example
git commit -m "feat(sharing): phase H2 — Next helper README + starter app handoff

Adds 'Cross-Product Handoff' top-level section to README (concept,
install, /auth/handoff route + landing snippets, multi-account UX,
troubleshooting). Starter app at test-app/ now ships a working
destination-side handoff (route handler + landing page), which the
Phase G2 Playwright harness drives. .env.local.example documents the
two new optional envvars.

Phase: H2 of docs/plans/InterProductCommunication-2026-05-27/"
```

## Note for G2 (Playwright harness)

Phase G2's Playwright suite navigates the starter app at the URLs
created in this phase. If G2 already shipped before H2, re-run its suite
after this phase lands to confirm nothing drifted.
