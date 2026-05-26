# Phase F4 — `multiAccountAutoAdd` browser primitive

**Repo:** `auth-service-nextjs`
**Spec section:** §8 (Multi-Account Auto-Add)
**Depends on:** F3 (message type), A6 (iframe handler)

## Goal

A pure-browser async function that:
1. Sends `ADD_ACCOUNT_WITH_TOKEN` to the auth-service iframe with `{accountUuid, sessionToken}` via the existing `PostMessageClient`
2. Awaits an `ADD_ACCOUNT_WITH_TOKEN_OK` ack (5s timeout)
3. On ack, calls the existing `switchAccount(accountUuid)` from the consumer's switcher context
4. On `ADD_ACCOUNT_WITH_TOKEN_FAILED` (or timeout) throws a typed `MultiAccountAutoAddError`

Reuses the existing `PostMessageClient` plumbing — no new transport. The caller (typically `<HandoffLanding>` in F5) provides the switcher API via dependency injection so this primitive is trivially mockable.

## Files

- **Create:** `src/sharing/multiAccountAutoAdd.ts`
- **Test:** `tests/unit/sharing/multiAccountAutoAdd.test.ts`

## Steps

### Step 1 — Write the failing test

```ts
// tests/unit/sharing/multiAccountAutoAdd.test.ts
// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  multiAccountAutoAdd,
  MultiAccountAutoAddError,
} from '../../../src/sharing/multiAccountAutoAdd';
import { MESSAGE_TYPES } from '../../../src/lib/message-protocol';

interface FakeClient {
  on: ReturnType<typeof vi.fn>;
  off: ReturnType<typeof vi.fn>;
  _send: ReturnType<typeof vi.fn>;
  _handlers: Map<string, Function[]>;
  _emit: (type: string, payload: unknown) => void;
}

function makeClient(): FakeClient {
  const handlers = new Map<string, Function[]>();
  return {
    _handlers: handlers,
    on: vi.fn((type: string, fn: Function) => {
      if (!handlers.has(type)) handlers.set(type, []);
      handlers.get(type)!.push(fn);
    }),
    off: vi.fn((type: string, fn: Function) => {
      const arr = handlers.get(type);
      if (arr) handlers.set(type, arr.filter(h => h !== fn));
    }),
    _send: vi.fn(),
    _emit: (type, payload) => {
      (handlers.get(type) ?? []).forEach(h => h({ payload }));
    },
  };
}

describe('multiAccountAutoAdd', () => {
  let switchAccount: ReturnType<typeof vi.fn>;
  let addAccount: ReturnType<typeof vi.fn>;
  let client: FakeClient;

  beforeEach(() => {
    switchAccount = vi.fn().mockResolvedValue(undefined);
    addAccount = vi.fn().mockResolvedValue(undefined);
    client = makeClient();
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('sends ADD_ACCOUNT_WITH_TOKEN and switches on OK ack', async () => {
    const promise = multiAccountAutoAdd({
      accountUuid: 'u-1',
      sessionToken: 'sess_abc',
      switcher: { addAccount, switchAccount },
      client: client as any,
      sendMessage: client._send,
    });

    expect(client._send).toHaveBeenCalledWith(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN, {
      accountUuid: 'u-1',
      sessionToken: 'sess_abc',
    });
    expect(client.on).toHaveBeenCalledWith(
      MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN_OK,
      expect.any(Function),
    );
    expect(client.on).toHaveBeenCalledWith(
      MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN_FAILED,
      expect.any(Function),
    );

    client._emit(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN_OK, { accountUuid: 'u-1' });
    await promise;

    expect(switchAccount).toHaveBeenCalledWith('u-1');
    expect(client.off).toHaveBeenCalledTimes(2);
  });

  it('throws MultiAccountAutoAddError on FAILED ack', async () => {
    const promise = multiAccountAutoAdd({
      accountUuid: 'u-1',
      sessionToken: 'sess',
      switcher: { addAccount, switchAccount },
      client: client as any,
      sendMessage: client._send,
    });

    client._emit(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN_FAILED, {
      reason: 'http_401', message: 'invalid',
    });

    await expect(promise).rejects.toBeInstanceOf(MultiAccountAutoAddError);
    await expect(promise).rejects.toMatchObject({ reason: 'http_401' });
    expect(switchAccount).not.toHaveBeenCalled();
  });

  it('throws on timeout (default 5s)', async () => {
    const promise = multiAccountAutoAdd({
      accountUuid: 'u-1',
      sessionToken: 'sess',
      switcher: { addAccount, switchAccount },
      client: client as any,
      sendMessage: client._send,
    });

    vi.advanceTimersByTime(5_000);

    await expect(promise).rejects.toBeInstanceOf(MultiAccountAutoAddError);
    await expect(promise).rejects.toMatchObject({ reason: 'timeout' });
    expect(switchAccount).not.toHaveBeenCalled();
    expect(client.off).toHaveBeenCalledTimes(2);
  });

  it('honors custom timeoutMs', async () => {
    const promise = multiAccountAutoAdd({
      accountUuid: 'u-1',
      sessionToken: 'sess',
      switcher: { addAccount, switchAccount },
      client: client as any,
      sendMessage: client._send,
      timeoutMs: 100,
    });
    vi.advanceTimersByTime(100);
    await expect(promise).rejects.toMatchObject({ reason: 'timeout' });
  });

  it('rejects mismatched accountUuid in OK ack', async () => {
    const promise = multiAccountAutoAdd({
      accountUuid: 'u-1',
      sessionToken: 'sess',
      switcher: { addAccount, switchAccount },
      client: client as any,
      sendMessage: client._send,
    });
    client._emit(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN_OK, { accountUuid: 'u-OTHER' });
    await expect(promise).rejects.toMatchObject({ reason: 'mismatch' });
    expect(switchAccount).not.toHaveBeenCalled();
  });
});
```

### Step 2 — Run, expect failure

```bash
npx vitest run tests/unit/sharing/multiAccountAutoAdd.test.ts
```

### Step 3 — Implement the primitive

```ts
// src/sharing/multiAccountAutoAdd.ts
import { MESSAGE_TYPES } from '../lib/message-protocol';
import type {
  AddAccountWithTokenFailedPayload,
  AddAccountWithTokenOkPayload,
  AddAccountWithTokenPayload,
} from '../lib/message-protocol';
import type { PostMessageClient } from '../lib/client';

export class MultiAccountAutoAddError extends Error {
  constructor(public readonly reason: string, message?: string) {
    super(message ?? `multi-account auto-add failed: ${reason}`);
    this.name = 'MultiAccountAutoAddError';
  }
}

export interface SwitcherApi {
  addAccount: () => Promise<void>;
  switchAccount: (uuid: string) => Promise<void>;
}

export interface MultiAccountAutoAddOptions {
  accountUuid: string;
  sessionToken: string;
  switcher: SwitcherApi;
  /** The live PostMessageClient (typically from AccountSwitcherProvider context). */
  client: Pick<PostMessageClient, 'on' | 'off'>;
  /**
   * Function that posts a typed message to the iframe. Defaults to the
   * private `sendMessage` of PostMessageClient — injected here for unit
   * testability since the real method is private.
   */
  sendMessage: (type: string, payload: unknown) => void;
  /** Override the default 5000ms ack timeout. */
  timeoutMs?: number;
}

const DEFAULT_TIMEOUT_MS = 5_000;

/**
 * Push a pre-authenticated account into the destination's account switcher.
 *
 * 1. Sends ADD_ACCOUNT_WITH_TOKEN over postMessage
 * 2. Awaits ADD_ACCOUNT_WITH_TOKEN_OK / _FAILED (timeout-guarded)
 * 3. On OK, calls switcher.switchAccount(accountUuid)
 * 4. On FAILED or timeout, throws MultiAccountAutoAddError
 *
 * Used by HandoffLanding (F5). The iframe-side handler is the A6 work.
 */
export async function multiAccountAutoAdd(
  options: MultiAccountAutoAddOptions,
): Promise<void> {
  const { accountUuid, sessionToken, switcher, client, sendMessage } = options;
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;

  const payload: AddAccountWithTokenPayload = { accountUuid, sessionToken };

  await new Promise<void>((resolve, reject) => {
    let settled = false;
    let timer: ReturnType<typeof setTimeout> | null = null;

    const cleanup = () => {
      client.off(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN_OK, onOk);
      client.off(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN_FAILED, onFailed);
      if (timer) clearTimeout(timer);
    };

    const settle = (fn: () => void) => {
      if (settled) return;
      settled = true;
      cleanup();
      fn();
    };

    const onOk = (msg: { payload?: AddAccountWithTokenOkPayload }) => {
      const ack = msg?.payload;
      if (!ack || ack.accountUuid !== accountUuid) {
        settle(() => reject(new MultiAccountAutoAddError(
          'mismatch',
          `ack uuid ${ack?.accountUuid ?? 'undefined'} != requested ${accountUuid}`,
        )));
        return;
      }
      settle(() => resolve());
    };

    const onFailed = (msg: { payload?: AddAccountWithTokenFailedPayload }) => {
      const fail = msg?.payload;
      settle(() => reject(new MultiAccountAutoAddError(
        fail?.reason ?? 'unknown',
        fail?.message,
      )));
    };

    client.on(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN_OK, onOk);
    client.on(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN_FAILED, onFailed);

    timer = setTimeout(() => {
      settle(() => reject(new MultiAccountAutoAddError(
        'timeout',
        `no ack within ${timeoutMs}ms`,
      )));
    }, timeoutMs);

    sendMessage(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN, payload);
  });

  await switcher.switchAccount(accountUuid);
}
```

### Step 4 — Run + commit

```bash
npx vitest run tests/unit/sharing/multiAccountAutoAdd.test.ts
# Expected: 5 passed.

npm run type-check

git add src/sharing/multiAccountAutoAdd.ts \
        tests/unit/sharing/multiAccountAutoAdd.test.ts
git commit -m "feat(sharing): phase F4 — multiAccountAutoAdd browser primitive

Sends ADD_ACCOUNT_WITH_TOKEN to the iframe, awaits the OK/FAILED ack
(5s default timeout), then switches to the new account via the
existing switcher API. Throws typed MultiAccountAutoAddError on any
failure path (FAILED, timeout, uuid-mismatch). Pure DI: client +
sendMessage + switcher are injected, so the primitive is fully
unit-testable without a real iframe.

Phase: F4 of docs/plans/InterProductCommunication-2026-05-27/"
```
