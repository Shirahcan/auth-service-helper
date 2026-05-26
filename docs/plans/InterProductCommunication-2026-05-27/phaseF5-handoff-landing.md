# Phase F5 — `HandoffLanding` + `useHandoffArrival` + toast

**Repo:** `auth-service-nextjs`
**Spec section:** §8 (Multi-Account Auto-Add — Client-Side Landing)
**Depends on:** F4 (multiAccountAutoAdd), F3 (message types)

## Goal

Ship the three pieces that complete the destination's user-facing handoff flow:

1. `<HandoffLanding>` — client component the consumer drops at `app/auth/handoff/landing/page.tsx`. Reads `?account_uuid&session_token&next&toast` from the URL, calls `multiAccountAutoAdd`, then `router.replace(next?_toast=…)`.
2. `useHandoffArrival()` — hook the consumer mounts on every page (or in a top-level layout). On mount it reads `?_toast=…`, returns the message, then scrubs the param from the URL via `router.replace`.
3. `<HandoffArrivalToast>` — a minimal inline-styled banner showing "Now viewing as Jane Doe" for ~5s.

Also: extend the package `exports` map to expose `./sharing` (the F1-F4 + F5 module surface) and ship `src/sharing/index.ts` as a barrel.

## Files

- **Create:** `src/sharing/HandoffLanding.tsx`
- **Create:** `src/hooks/useHandoffArrival.ts`
- **Create:** `src/components/HandoffArrivalToast.tsx`
- **Create:** `src/sharing/index.ts` (barrel)
- **Modify:** `package.json` (add `./sharing` to `exports`)
- **Test:** `tests/unit/hooks/useHandoffArrival.test.ts`

## Steps

### Step 1 — Write the failing test (the most-mockable piece is the hook; the component is exercised end-to-end in Phase G2 Playwright)

```ts
// tests/unit/hooks/useHandoffArrival.test.ts
// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useHandoffArrival } from '../../../src/hooks/useHandoffArrival';

const replaceMock = vi.fn();
let searchParamsValue = new URLSearchParams();
let pathnameValue = '/dashboard';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace: replaceMock }),
  useSearchParams: () => searchParamsValue,
  usePathname: () => pathnameValue,
}));

describe('useHandoffArrival', () => {
  beforeEach(() => {
    replaceMock.mockReset();
    searchParamsValue = new URLSearchParams();
    pathnameValue = '/dashboard';
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('returns null toast when no _toast param', () => {
    const { result } = renderHook(() => useHandoffArrival());
    expect(result.current.toast).toBeNull();
    expect(replaceMock).not.toHaveBeenCalled();
  });

  it('returns decoded toast and scrubs param', () => {
    searchParamsValue = new URLSearchParams('?_toast=Now+viewing+as+Jane+Doe&foo=bar');
    pathnameValue = '/dashboard';
    const { result } = renderHook(() => useHandoffArrival());
    expect(result.current.toast).toBe('Now viewing as Jane Doe');
    expect(replaceMock).toHaveBeenCalledOnce();
    // Scrubbed URL keeps other params, drops _toast
    const calledWith = replaceMock.mock.calls[0][0] as string;
    expect(calledWith.startsWith('/dashboard?')).toBe(true);
    expect(calledWith).toContain('foo=bar');
    expect(calledWith).not.toContain('_toast');
  });

  it('scrubs to bare pathname when _toast is the only param', () => {
    searchParamsValue = new URLSearchParams('?_toast=Hello');
    pathnameValue = '/cases/42';
    const { result } = renderHook(() => useHandoffArrival());
    expect(result.current.toast).toBe('Hello');
    expect(replaceMock).toHaveBeenCalledWith('/cases/42');
  });

  it('only fires once per mount even if rerendered', () => {
    searchParamsValue = new URLSearchParams('?_toast=Hi');
    const { rerender } = renderHook(() => useHandoffArrival());
    rerender();
    rerender();
    expect(replaceMock).toHaveBeenCalledTimes(1);
  });

  it('dismiss() clears the toast value', () => {
    searchParamsValue = new URLSearchParams('?_toast=Hi');
    const { result, rerender } = renderHook(() => useHandoffArrival());
    expect(result.current.toast).toBe('Hi');
    result.current.dismiss();
    rerender();
    expect(result.current.toast).toBeNull();
  });
});
```

Install `@testing-library/react` for this test:

```bash
npm install --save-dev @testing-library/react @testing-library/dom
```

### Step 2 — Run, expect failure

```bash
npx vitest run tests/unit/hooks/useHandoffArrival.test.ts
```

### Step 3 — Implement the hook + component + landing + barrel

```ts
// src/hooks/useHandoffArrival.ts
'use client';
import { useCallback, useEffect, useRef, useState } from 'react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';

const PARAM = '_toast';

export interface HandoffArrival {
  toast: string | null;
  dismiss: () => void;
}

/**
 * Reads the `?_toast=…` query param once on mount, scrubs it from the URL via
 * `router.replace`, and returns the decoded message. Intended to be mounted in
 * a top-level layout so any page the user lands on after a cross-product
 * handoff (F2 → F5) can render the arrival banner.
 */
export function useHandoffArrival(): HandoffArrival {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const fired = useRef(false);
  const [toast, setToast] = useState<string | null>(null);

  useEffect(() => {
    if (fired.current) return;
    fired.current = true;

    const raw = params?.get(PARAM);
    if (!raw) return;

    setToast(raw);

    const remaining = new URLSearchParams(params?.toString() ?? '');
    remaining.delete(PARAM);
    const qs = remaining.toString();
    router.replace(qs ? `${pathname}?${qs}` : pathname);
  }, [params, pathname, router]);

  const dismiss = useCallback(() => setToast(null), []);

  return { toast, dismiss };
}
```

```tsx
// src/components/HandoffArrivalToast.tsx
'use client';
import { useEffect } from 'react';
import { useHandoffArrival } from '../hooks/useHandoffArrival';

export interface HandoffArrivalToastProps {
  /** Auto-dismiss after this many ms. Default 5000. Pass 0 to disable. */
  autoDismissMs?: number;
}

/**
 * Drop-in arrival banner — pair with the F2 → F5 handoff flow. Renders nothing
 * when there's no `?_toast=` to show.
 */
export function HandoffArrivalToast({ autoDismissMs = 5_000 }: HandoffArrivalToastProps) {
  const { toast, dismiss } = useHandoffArrival();

  useEffect(() => {
    if (!toast || autoDismissMs <= 0) return;
    const t = setTimeout(dismiss, autoDismissMs);
    return () => clearTimeout(t);
  }, [toast, autoDismissMs, dismiss]);

  if (!toast) return null;

  return (
    <div
      role="status"
      aria-live="polite"
      style={{
        position: 'fixed',
        top: 16,
        left: '50%',
        transform: 'translateX(-50%)',
        zIndex: 9999,
        background: '#111',
        color: '#fff',
        padding: '10px 16px',
        borderRadius: 8,
        boxShadow: '0 4px 14px rgba(0,0,0,0.2)',
        fontFamily: 'system-ui, sans-serif',
        fontSize: 14,
        display: 'flex',
        alignItems: 'center',
        gap: 12,
      }}
    >
      <span>{toast}</span>
      <button
        type="button"
        onClick={dismiss}
        aria-label="Dismiss"
        style={{
          background: 'transparent',
          border: 'none',
          color: '#fff',
          cursor: 'pointer',
          fontSize: 16,
          lineHeight: 1,
        }}
      >
        ×
      </button>
    </div>
  );
}
```

```tsx
// src/sharing/HandoffLanding.tsx
'use client';
import { useEffect, useRef, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useAccountSwitcher } from '../hooks/useAccountSwitcher';
import { multiAccountAutoAdd, MultiAccountAutoAddError } from './multiAccountAutoAdd';
import { MESSAGE_TYPES } from '../lib/message-protocol';

export interface HandoffLandingProps {
  /** Override the splash text. */
  splash?: string;
  /** Where to send the user if the URL is missing required params. Default `/login`. */
  loginPath?: string;
}

/**
 * The page consumers drop at `app/auth/handoff/landing/page.tsx`:
 *
 * ```tsx
 * 'use client';
 * import { HandoffLanding } from '@benbraide/auth-service-nextjs/sharing';
 * export default function Page() { return <HandoffLanding />; }
 * ```
 *
 * Reads `account_uuid`, `session_token`, `next`, `toast` from the URL (set by
 * the F2 route handler), runs `multiAccountAutoAdd`, then redirects to
 * `${next}?_toast=${toast}` so the arriving page's `HandoffArrivalToast` can
 * show the banner.
 */
export function HandoffLanding({
  splash = 'Setting up your account…',
  loginPath = '/login',
}: HandoffLandingProps) {
  const router = useRouter();
  const params = useSearchParams();
  const switcher = useAccountSwitcher();
  const fired = useRef(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (fired.current) return;
    fired.current = true;

    const accountUuid = params?.get('account_uuid');
    const sessionToken = params?.get('session_token');
    const next = params?.get('next') ?? '/';
    const toast = params?.get('toast') ?? '';

    if (!accountUuid || !sessionToken) {
      router.replace(`${loginPath}?error=handoff_missing_params`);
      return;
    }

    // The PostMessageClient lives inside AccountSwitcherProvider — exposed via
    // the switcher context. Consumers using this component MUST have the
    // provider mounted; otherwise `switcher.client` is null and we fall back
    // to an error redirect.
    const client = (switcher as unknown as { client?: any }).client;
    const sendMessage = (switcher as unknown as { sendMessage?: (t: string, p: unknown) => void }).sendMessage;
    if (!client || !sendMessage) {
      router.replace(`${loginPath}?error=handoff_provider_missing`);
      return;
    }

    (async () => {
      try {
        await multiAccountAutoAdd({
          accountUuid,
          sessionToken,
          switcher: {
            addAccount: switcher.addAccount,
            switchAccount: switcher.switchAccount,
          },
          client,
          sendMessage: (type, payload) => sendMessage(type, payload),
        });
        const dest = toast
          ? `${next}${next.includes('?') ? '&' : '?'}_toast=${encodeURIComponent(toast)}`
          : next;
        router.replace(dest);
      } catch (err) {
        const reason = err instanceof MultiAccountAutoAddError ? err.reason : 'unknown';
        setError(reason);
        router.replace(`${loginPath}?error=handoff_${reason}`);
      }
    })();
    // Eslint may complain about MESSAGE_TYPES being unused here — kept imported
    // so tree-shakers don't drop the enum during build-time analysis.
    void MESSAGE_TYPES;
  }, [params, router, switcher, loginPath]);

  return (
    <div
      style={{
        minHeight: '60vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        fontFamily: 'system-ui, sans-serif',
        color: '#444',
      }}
      role="status"
      aria-live="polite"
    >
      {error ? `Sign-in failed (${error})…` : splash}
    </div>
  );
}
```

```ts
// src/sharing/index.ts — barrel export for the ./sharing subpath
export { consumeHandoffToken } from './consumeHandoffToken';
export { createHandoffRouteHandler } from './createHandoffRouteHandler';
export type { CreateHandoffRouteHandlerOptions } from './createHandoffRouteHandler';
export {
  multiAccountAutoAdd,
  MultiAccountAutoAddError,
} from './multiAccountAutoAdd';
export type {
  MultiAccountAutoAddOptions,
  SwitcherApi,
} from './multiAccountAutoAdd';
export { HandoffLanding } from './HandoffLanding';
export type { HandoffLandingProps } from './HandoffLanding';
export type {
  HandoffResult,
  HandoffSuccess,
  HandoffFailure,
  HandoffFailureReason,
  HandoffUser,
  ConsumeHandoffTokenOptions,
} from './types';
```

### Step 4 — Wire the new export subpath in `package.json`

Add `./sharing` to the `exports` map. Existing entries unchanged:

```json
{
  "exports": {
    ".": {
      "types": "./dist/index.d.ts",
      "import": "./dist/index.mjs",
      "require": "./dist/index.js"
    },
    "./components": {
      "types": "./dist/components/index.d.ts",
      "import": "./dist/components/index.mjs",
      "require": "./dist/components/index.js"
    },
    "./hooks": {
      "types": "./dist/hooks/index.d.ts",
      "import": "./dist/hooks/index.mjs",
      "require": "./dist/hooks/index.js"
    },
    "./sharing": {
      "types": "./dist/sharing/index.d.ts",
      "import": "./dist/sharing/index.mjs",
      "require": "./dist/sharing/index.js"
    }
  }
}
```

If `tsup.config.ts` enumerates entries explicitly, append `'src/sharing/index.ts'` (and confirm `src/hooks/index.ts` re-exports `useHandoffArrival`, `src/components/index.ts` re-exports `HandoffArrivalToast`). When in doubt, run `Grep` for `entry:` in `tsup.config.*`.

Also append to the existing `src/hooks/index.ts` (and `src/components/index.ts`):

```ts
// src/hooks/index.ts (append)
export { useHandoffArrival } from './useHandoffArrival';
export type { HandoffArrival } from './useHandoffArrival';
```

```ts
// src/components/index.ts (append)
export { HandoffArrivalToast } from './HandoffArrivalToast';
export type { HandoffArrivalToastProps } from './HandoffArrivalToast';
```

### Step 5 — Run + commit

```bash
npx vitest run tests/unit/hooks/useHandoffArrival.test.ts
# Expected: 5 passed.

npm run type-check
npm run build   # tsup — sanity check the new subpath actually emits dist/sharing/

git add src/sharing/HandoffLanding.tsx \
        src/sharing/index.ts \
        src/hooks/useHandoffArrival.ts \
        src/hooks/index.ts \
        src/components/HandoffArrivalToast.tsx \
        src/components/index.ts \
        tests/unit/hooks/useHandoffArrival.test.ts \
        package.json package-lock.json \
        tsup.config.ts
git commit -m "feat(sharing): phase F5 — HandoffLanding + useHandoffArrival + toast

Three drop-in pieces that complete the destination-side handoff UX:
- HandoffLanding client component (app/auth/handoff/landing/page.tsx)
  drives multiAccountAutoAdd then redirects to ?_toast=
- useHandoffArrival reads _toast on mount + scrubs the param
- HandoffArrivalToast renders the 'Now viewing as <name>' banner

Also exposes the ./sharing subpath via the package exports map and
adds the new hook/component to their respective barrels.

Phase: F5 of docs/plans/InterProductCommunication-2026-05-27/"
```
