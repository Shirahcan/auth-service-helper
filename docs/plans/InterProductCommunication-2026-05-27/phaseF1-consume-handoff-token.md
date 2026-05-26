# Phase F1 — `consumeHandoffToken` (server-side)

**Repo:** `auth-service-nextjs`
**Spec section:** §8 (Destination-Side `/auth/handoff` Route)
**Depends on:** A4 (handoff exchange endpoint live), D2c (helper-provided `InboundHandoffExchangeController` on destination backend)

## Goal

A server-side function that the destination's Next `/auth/handoff` route calls to redeem a handoff token. It POSTs to the destination's PHP backend (`POST /api/v1/inbound/handoff/exchange` — the helper-mounted controller from D2c). The PHP controller is responsible for relaying to auth-service `POST /handoff-tokens/{token}/exchange` with the service's `X-API-KEY`. This Next module never speaks to auth-service directly — keeping API keys server-side in PHP.

Returns a discriminated union: `HandoffSuccess` (ok, user, sessionToken, shareId, nextPath) or `HandoffFailure` (ok=false, reason). Failure reasons mirror the spec edge-case table (§13): `expired`, `consumed`, `wrong_target`, `unknown`, `network`.

Native `fetch` only — must work in Edge runtime. 5-second timeout via `AbortController`.

## Files

- **Create:** `src/sharing/types.ts`
- **Create:** `src/sharing/consumeHandoffToken.ts`
- **Test:** `tests/unit/sharing/consumeHandoffToken.test.ts`

## Steps

### Step 0 — Install Vitest (one-time setup, only if first F-phase to run)

Vitest is not yet in `package.json`. Add it as a dev dependency along with the jsdom env (used later in F4/F5). Skip if already installed.

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-nextjs
npm install --save-dev vitest @vitest/coverage-v8 jsdom @types/node
```

Add to `package.json` `scripts`:

```json
"test": "vitest run",
"test:watch": "vitest"
```

Create `vitest.config.ts` at the repo root:

```ts
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    globals: false,
    include: ['tests/**/*.test.ts', 'tests/**/*.test.tsx'],
    environmentMatchGlobs: [
      ['tests/unit/hooks/**', 'jsdom'],
      ['tests/unit/sharing/multiAccountAutoAdd.test.ts', 'jsdom'],
    ],
  },
});
```

### Step 1 — Write the failing test

```ts
// tests/unit/sharing/consumeHandoffToken.test.ts
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { consumeHandoffToken } from '../../../src/sharing/consumeHandoffToken';

const FAKE_BACKEND = 'https://destination.example';
const FAKE_API_KEY = 'svc_destination_key';
const FAKE_INTERNAL = 'internal_token_xyz';

describe('consumeHandoffToken', () => {
  let fetchSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchSpy = vi.fn();
    globalThis.fetch = fetchSpy as unknown as typeof fetch;
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns ok with user + sessionToken on 200', async () => {
    fetchSpy.mockResolvedValueOnce(
      new Response(
        JSON.stringify({
          user: { uuid: 'u-1', name: 'Jane', email: 'jane@example.com' },
          session_token: 'sess_abc',
          share_id: 'share_1',
          next_path: '/cases/42',
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    const result = await consumeHandoffToken({
      token: 't_123',
      backendUrl: FAKE_BACKEND,
      serviceApiKey: FAKE_API_KEY,
      internalToken: FAKE_INTERNAL,
    });

    expect(result.ok).toBe(true);
    if (result.ok) {
      expect(result.user.uuid).toBe('u-1');
      expect(result.sessionToken).toBe('sess_abc');
      expect(result.shareId).toBe('share_1');
      expect(result.nextPath).toBe('/cases/42');
    }

    expect(fetchSpy).toHaveBeenCalledOnce();
    const [url, init] = fetchSpy.mock.calls[0];
    expect(url).toBe(`${FAKE_BACKEND}/api/v1/inbound/handoff/exchange`);
    expect((init as RequestInit).method).toBe('POST');
    expect(JSON.parse((init as RequestInit).body as string)).toEqual({ token: 't_123' });
    const headers = (init as RequestInit).headers as Record<string, string>;
    expect(headers['X-API-KEY']).toBe(FAKE_API_KEY);
    expect(headers['X-Internal-Token']).toBe(FAKE_INTERNAL);
  });

  it('maps 410 to expired', async () => {
    fetchSpy.mockResolvedValueOnce(
      new Response(JSON.stringify({ error: 'expired' }), { status: 410 }),
    );
    const r = await consumeHandoffToken({
      token: 't', backendUrl: FAKE_BACKEND, serviceApiKey: FAKE_API_KEY, internalToken: FAKE_INTERNAL,
    });
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.reason).toBe('expired');
  });

  it('maps 409 to consumed', async () => {
    fetchSpy.mockResolvedValueOnce(new Response('{}', { status: 409 }));
    const r = await consumeHandoffToken({
      token: 't', backendUrl: FAKE_BACKEND, serviceApiKey: FAKE_API_KEY, internalToken: FAKE_INTERNAL,
    });
    if (!r.ok) expect(r.reason).toBe('consumed');
  });

  it('maps 403 to wrong_target', async () => {
    fetchSpy.mockResolvedValueOnce(new Response('{}', { status: 403 }));
    const r = await consumeHandoffToken({
      token: 't', backendUrl: FAKE_BACKEND, serviceApiKey: FAKE_API_KEY, internalToken: FAKE_INTERNAL,
    });
    if (!r.ok) expect(r.reason).toBe('wrong_target');
  });

  it('maps network error to network', async () => {
    fetchSpy.mockRejectedValueOnce(new TypeError('fetch failed'));
    const r = await consumeHandoffToken({
      token: 't', backendUrl: FAKE_BACKEND, serviceApiKey: FAKE_API_KEY, internalToken: FAKE_INTERNAL,
    });
    if (!r.ok) expect(r.reason).toBe('network');
  });

  it('maps 500/unknown shape to unknown', async () => {
    fetchSpy.mockResolvedValueOnce(new Response('boom', { status: 500 }));
    const r = await consumeHandoffToken({
      token: 't', backendUrl: FAKE_BACKEND, serviceApiKey: FAKE_API_KEY, internalToken: FAKE_INTERNAL,
    });
    if (!r.ok) expect(r.reason).toBe('unknown');
  });

  it('aborts after timeoutMs', async () => {
    fetchSpy.mockImplementationOnce(
      (_url: string, init: RequestInit) =>
        new Promise((_resolve, reject) => {
          init.signal?.addEventListener('abort', () =>
            reject(new DOMException('aborted', 'AbortError')),
          );
        }),
    );
    const r = await consumeHandoffToken({
      token: 't', backendUrl: FAKE_BACKEND, serviceApiKey: FAKE_API_KEY, internalToken: FAKE_INTERNAL,
      timeoutMs: 10,
    });
    if (!r.ok) expect(r.reason).toBe('network');
  });
});
```

### Step 2 — Run, expect failure

```bash
npx vitest run tests/unit/sharing/consumeHandoffToken.test.ts
```

### Step 3 — Implement types + function

```ts
// src/sharing/types.ts

export interface HandoffUser {
  uuid: string;
  name: string;
  email: string;
}

export interface HandoffSuccess {
  ok: true;
  user: HandoffUser;
  sessionToken: string;
  shareId: string;
  nextPath: string;
}

export type HandoffFailureReason =
  | 'expired'
  | 'consumed'
  | 'wrong_target'
  | 'unknown'
  | 'network';

export interface HandoffFailure {
  ok: false;
  reason: HandoffFailureReason;
  status?: number;
}

export type HandoffResult = HandoffSuccess | HandoffFailure;

export interface ConsumeHandoffTokenOptions {
  /** Opaque handoff token from `?token=` */
  token: string;
  /** Destination's own PHP backend base URL, e.g. `process.env.NEXT_PUBLIC_BACKEND_URL` */
  backendUrl: string;
  /** Destination's service API key (server-side env var) */
  serviceApiKey: string;
  /** Internal shared secret between this Next server and its PHP backend */
  internalToken: string;
  /** Override the default 5000ms abort timeout */
  timeoutMs?: number;
}
```

```ts
// src/sharing/consumeHandoffToken.ts
import type {
  ConsumeHandoffTokenOptions,
  HandoffFailureReason,
  HandoffResult,
} from './types';

const DEFAULT_TIMEOUT_MS = 5_000;

function mapStatusToReason(status: number): HandoffFailureReason {
  switch (status) {
    case 410: return 'expired';
    case 409: return 'consumed';
    case 403: return 'wrong_target';
    default:  return 'unknown';
  }
}

/**
 * Server-side handoff token redemption.
 *
 * Calls the destination's own PHP backend at `POST /api/v1/inbound/handoff/exchange`
 * (controller mounted by `auth-service-helper` in phase D2c). The backend relays
 * to auth-service `POST /handoff-tokens/{token}/exchange` with its X-API-KEY,
 * then returns the resolved user + session token to this Next layer.
 *
 * Never call auth-service directly from here — keep service API keys in PHP.
 */
export async function consumeHandoffToken(
  options: ConsumeHandoffTokenOptions,
): Promise<HandoffResult> {
  const { token, backendUrl, serviceApiKey, internalToken } = options;
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  let response: Response;
  try {
    response = await fetch(`${backendUrl}/api/v1/inbound/handoff/exchange`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-API-KEY': serviceApiKey,
        'X-Internal-Token': internalToken,
      },
      body: JSON.stringify({ token }),
      signal: controller.signal,
      cache: 'no-store',
    });
  } catch {
    clearTimeout(timer);
    return { ok: false, reason: 'network' };
  }
  clearTimeout(timer);

  if (!response.ok) {
    return { ok: false, reason: mapStatusToReason(response.status), status: response.status };
  }

  let body: unknown;
  try {
    body = await response.json();
  } catch {
    return { ok: false, reason: 'unknown', status: response.status };
  }

  if (!isExchangeBody(body)) {
    return { ok: false, reason: 'unknown', status: response.status };
  }

  return {
    ok: true,
    user: {
      uuid: body.user.uuid,
      name: body.user.name,
      email: body.user.email,
    },
    sessionToken: body.session_token,
    shareId: body.share_id,
    nextPath: body.next_path ?? '/',
  };
}

function isExchangeBody(value: unknown): value is {
  user: { uuid: string; name: string; email: string };
  session_token: string;
  share_id: string;
  next_path?: string | null;
} {
  if (!value || typeof value !== 'object') return false;
  const v = value as Record<string, unknown>;
  if (typeof v.session_token !== 'string') return false;
  if (typeof v.share_id !== 'string') return false;
  const u = v.user as Record<string, unknown> | undefined;
  if (!u || typeof u !== 'object') return false;
  return (
    typeof u.uuid === 'string' &&
    typeof u.name === 'string' &&
    typeof u.email === 'string'
  );
}
```

### Step 4 — Run + commit

```bash
npx vitest run tests/unit/sharing/consumeHandoffToken.test.ts
# Expected: 7 passed.

npm run type-check

git add src/sharing/types.ts \
        src/sharing/consumeHandoffToken.ts \
        tests/unit/sharing/consumeHandoffToken.test.ts \
        vitest.config.ts package.json package-lock.json
git commit -m "feat(sharing): phase F1 — consumeHandoffToken (server-side)

Discriminated-union API for redeeming a handoff token via the
destination's own PHP backend (helper-mounted controller). Edge-safe
(native fetch + AbortController, 5s default timeout). Maps
410/409/403 to typed failure reasons; never talks to auth-service
directly.

Phase: F1 of docs/plans/InterProductCommunication-2026-05-27/"
```
