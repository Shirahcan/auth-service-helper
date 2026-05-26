# Phase F2 — `/auth/handoff` route handler installer

**Repo:** `auth-service-nextjs`
**Spec section:** §8 (Destination-Side `/auth/handoff` Route)
**Depends on:** F1

## Goal

The package can't ship an App Router route file directly — Next.js routes are detected by filesystem position in the consumer app, not by package imports. Instead, ship a `createHandoffRouteHandler({ ... })` factory that returns a Next 13+ App Router `GET` handler. Consumers wire it in three lines inside their own `src/app/auth/handoff/route.ts`.

The handler:
1. Reads `?token=` and `?next=` from the URL
2. If `token` is missing, redirects to `loginPath`
3. Calls `consumeHandoffToken` (F1) to redeem
4. On failure, redirects to `${loginPath}?error=handoff_${reason}`
5. On success, redirects to `/auth/handoff/landing?account_uuid=...&session_token=...&next=...&toast=Now+viewing+as+<name>`

The landing page (F5) takes it from there and runs the multi-account auto-add.

## Files

- **Create:** `src/sharing/createHandoffRouteHandler.ts`
- **Test:** `tests/unit/sharing/createHandoffRouteHandler.test.ts`
- **Doc snippet** lives inline in this phase file — consumers copy-pasta it. (Phase H2 will lift it into the README.)

## Steps

### Step 1 — Write the failing test

```ts
// tests/unit/sharing/createHandoffRouteHandler.test.ts
import { beforeEach, describe, expect, it, vi } from 'vitest';

const consumeMock = vi.fn();
vi.mock('../../../src/sharing/consumeHandoffToken', () => ({
  consumeHandoffToken: (...args: unknown[]) => consumeMock(...args),
}));

import { createHandoffRouteHandler } from '../../../src/sharing/createHandoffRouteHandler';

const OPTS = {
  backendUrl: 'https://destination.example',
  serviceApiKey: 'svc_key',
  internalToken: 'internal_xyz',
};

function reqWith(query: string): Request {
  return new Request(`https://destination.example/auth/handoff${query}`);
}

describe('createHandoffRouteHandler', () => {
  beforeEach(() => consumeMock.mockReset());

  it('redirects to /login when token missing', async () => {
    const GET = createHandoffRouteHandler(OPTS);
    const res = await GET(reqWith(''));
    expect(res.status).toBe(302);
    expect(res.headers.get('location')).toBe('https://destination.example/login');
    expect(consumeMock).not.toHaveBeenCalled();
  });

  it('honors custom loginPath when token missing', async () => {
    const GET = createHandoffRouteHandler({ ...OPTS, loginPath: '/signin' });
    const res = await GET(reqWith(''));
    expect(res.headers.get('location')).toBe('https://destination.example/signin');
  });

  it('redirects to login with error reason on failure', async () => {
    consumeMock.mockResolvedValueOnce({ ok: false, reason: 'expired' });
    const GET = createHandoffRouteHandler(OPTS);
    const res = await GET(reqWith('?token=t_1'));
    expect(res.status).toBe(302);
    const loc = new URL(res.headers.get('location')!);
    expect(loc.pathname).toBe('/login');
    expect(loc.searchParams.get('error')).toBe('handoff_expired');
  });

  it('redirects to landing with all params on success', async () => {
    consumeMock.mockResolvedValueOnce({
      ok: true,
      user: { uuid: 'u-1', name: 'Jane Doe', email: 'jane@example.com' },
      sessionToken: 'sess_abc',
      shareId: 'share_1',
      nextPath: '/cases/42',
    });
    const GET = createHandoffRouteHandler(OPTS);
    const res = await GET(reqWith('?token=t_1&next=/dashboard'));
    expect(res.status).toBe(302);
    const loc = new URL(res.headers.get('location')!);
    expect(loc.pathname).toBe('/auth/handoff/landing');
    expect(loc.searchParams.get('account_uuid')).toBe('u-1');
    expect(loc.searchParams.get('session_token')).toBe('sess_abc');
    // route param wins over exchange response nextPath for redirect target
    expect(loc.searchParams.get('next')).toBe('/dashboard');
    expect(loc.searchParams.get('toast')).toBe('Now viewing as Jane Doe');
  });

  it('falls back to exchange nextPath when ?next missing', async () => {
    consumeMock.mockResolvedValueOnce({
      ok: true,
      user: { uuid: 'u-1', name: 'Jane', email: 'jane@example.com' },
      sessionToken: 'sess', shareId: 's', nextPath: '/cases/99',
    });
    const GET = createHandoffRouteHandler(OPTS);
    const res = await GET(reqWith('?token=t_1'));
    const loc = new URL(res.headers.get('location')!);
    expect(loc.searchParams.get('next')).toBe('/cases/99');
  });

  it('passes token + creds to consumeHandoffToken', async () => {
    consumeMock.mockResolvedValueOnce({
      ok: true,
      user: { uuid: 'u', name: 'n', email: 'e' },
      sessionToken: 's', shareId: 'sh', nextPath: '/',
    });
    const GET = createHandoffRouteHandler(OPTS);
    await GET(reqWith('?token=abc&next=/x'));
    expect(consumeMock).toHaveBeenCalledWith({
      token: 'abc',
      backendUrl: OPTS.backendUrl,
      serviceApiKey: OPTS.serviceApiKey,
      internalToken: OPTS.internalToken,
    });
  });
});
```

### Step 2 — Run, expect failure

```bash
npx vitest run tests/unit/sharing/createHandoffRouteHandler.test.ts
```

### Step 3 — Implement the factory

```ts
// src/sharing/createHandoffRouteHandler.ts
import { consumeHandoffToken } from './consumeHandoffToken';

export interface CreateHandoffRouteHandlerOptions {
  /** Destination's own PHP backend base URL (server env var). */
  backendUrl: string;
  /** Destination's service API key (server env var). */
  serviceApiKey: string;
  /** Internal shared secret between this Next server and its PHP backend. */
  internalToken: string;
  /** Where to send users on missing/invalid tokens. Default: `/login`. */
  loginPath?: string;
  /** Landing page path that runs the client-side multi-account auto-add. Default: `/auth/handoff/landing`. */
  landingPath?: string;
}

/**
 * Build a Next App Router GET handler for `app/auth/handoff/route.ts`.
 *
 * Consumer usage (copy-pasta — package can't ship route files):
 *
 * ```ts
 * // src/app/auth/handoff/route.ts
 * import { createHandoffRouteHandler } from '@benbraide/auth-service-nextjs/sharing';
 *
 * export const GET = createHandoffRouteHandler({
 *   backendUrl: process.env.NEXT_PUBLIC_BACKEND_URL!,
 *   serviceApiKey: process.env.NEXT_SERVICE_API_KEY!,
 *   internalToken: process.env.NEXT_INTERNAL_TOKEN!,
 * });
 * ```
 */
export function createHandoffRouteHandler(
  options: CreateHandoffRouteHandlerOptions,
) {
  const loginPath = options.loginPath ?? '/login';
  const landingPath = options.landingPath ?? '/auth/handoff/landing';

  return async function GET(request: Request): Promise<Response> {
    const url = new URL(request.url);
    const token = url.searchParams.get('token');
    const nextParam = url.searchParams.get('next');

    if (!token) {
      return Response.redirect(new URL(loginPath, url), 302);
    }

    const result = await consumeHandoffToken({
      token,
      backendUrl: options.backendUrl,
      serviceApiKey: options.serviceApiKey,
      internalToken: options.internalToken,
    });

    if (!result.ok) {
      const errUrl = new URL(loginPath, url);
      errUrl.searchParams.set('error', `handoff_${result.reason}`);
      return Response.redirect(errUrl, 302);
    }

    const bridge = new URL(landingPath, url);
    bridge.searchParams.set('account_uuid', result.user.uuid);
    bridge.searchParams.set('session_token', result.sessionToken);
    bridge.searchParams.set('next', nextParam ?? result.nextPath);
    bridge.searchParams.set('toast', `Now viewing as ${result.user.name}`);
    return Response.redirect(bridge, 302);
  };
}
```

### Step 4 — Consumer documentation (copy-pasta they need)

Paste these into the consumer product's repo verbatim. (Phase H2 lifts these into the README.)

**`src/app/auth/handoff/route.ts`** (3-line installer):

```ts
import { createHandoffRouteHandler } from '@benbraide/auth-service-nextjs/sharing';

export const dynamic = 'force-dynamic';

export const GET = createHandoffRouteHandler({
  backendUrl:    process.env.NEXT_PUBLIC_BACKEND_URL!,
  serviceApiKey: process.env.NEXT_SERVICE_API_KEY!,
  internalToken: process.env.NEXT_INTERNAL_TOKEN!,
});
```

Required env vars on the destination Next app:

| Var | Source |
|---|---|
| `NEXT_PUBLIC_BACKEND_URL` | The destination's own PHP backend base URL |
| `NEXT_SERVICE_API_KEY` | Auth-service service-API key for this product (server-only) |
| `NEXT_INTERNAL_TOKEN` | Shared secret between Next and PHP backend (server-only) |

The landing page at `/auth/handoff/landing` is shipped in phase F5 as a `<HandoffLanding />` client component the consumer drops into `app/auth/handoff/landing/page.tsx`.

### Step 5 — Run + commit

```bash
npx vitest run tests/unit/sharing/createHandoffRouteHandler.test.ts
# Expected: 6 passed.

npm run type-check

git add src/sharing/createHandoffRouteHandler.ts \
        tests/unit/sharing/createHandoffRouteHandler.test.ts
git commit -m "feat(sharing): phase F2 — createHandoffRouteHandler factory

Factory returning a Next 13+ App Router GET handler for /auth/handoff.
Reads ?token/?next, redeems via F1, redirects to /auth/handoff/landing
on success or /login?error=handoff_<reason> on failure. Shipped as a
factory (not a route file) because Next route detection is filesystem-
based. Consumer installs in 3 lines.

Phase: F2 of docs/plans/InterProductCommunication-2026-05-27/"
```
