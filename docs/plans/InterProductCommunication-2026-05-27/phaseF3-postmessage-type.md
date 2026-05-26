# Phase F3 — `ADD_ACCOUNT_WITH_TOKEN` postMessage type

**Repo:** `auth-service-nextjs`
**Spec section:** §8 (New PostMessage Type)
**Depends on:** A6 (iframe handler that receives this message must exist on the auth-service side)

## Goal

Add one new message type — and its OK/FAILED response pair — to the existing `MESSAGE_TYPES` registry in `src/lib/message-protocol.ts`, plus the typed payload interfaces. The iframe handler shipped in A6 consumes this; the browser primitive in F4 sends it.

This phase is pure additive type-registry work — no runtime logic. It MUST NOT break any existing import: `MESSAGE_TYPES` is a `const` object exported by name; new keys append cleanly.

## Files

- **Modify:** `src/lib/message-protocol.ts`
- **Create:** `tests/unit/lib/message-protocol.test.ts` (if it doesn't already exist)

## Steps

### Step 1 — Write the failing test

```ts
// tests/unit/lib/message-protocol.test.ts
import { describe, expect, it } from 'vitest';
import {
  MESSAGE_TYPES,
  type AddAccountWithTokenPayload,
  type AddAccountWithTokenOkPayload,
  type AddAccountWithTokenFailedPayload,
} from '../../../src/lib/message-protocol';

describe('MESSAGE_TYPES — ADD_ACCOUNT_WITH_TOKEN trio', () => {
  it('exports the request type with the wire-format slug', () => {
    expect(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN).toBe('add-account-with-token');
  });

  it('exports the ok response type', () => {
    expect(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN_OK).toBe('add-account-with-token-ok');
  });

  it('exports the failed response type', () => {
    expect(MESSAGE_TYPES.ADD_ACCOUNT_WITH_TOKEN_FAILED).toBe('add-account-with-token-failed');
  });

  it('does not collide with any existing slug', () => {
    const values = Object.values(MESSAGE_TYPES);
    const unique = new Set(values);
    expect(unique.size).toBe(values.length);
  });

  it('AddAccountWithTokenPayload is structurally usable', () => {
    const payload: AddAccountWithTokenPayload = {
      accountUuid: 'u-1',
      sessionToken: 'sess_abc',
    };
    expect(payload.accountUuid).toBe('u-1');
    expect(payload.sessionToken).toBe('sess_abc');
  });

  it('AddAccountWithTokenOkPayload is structurally usable', () => {
    const ok: AddAccountWithTokenOkPayload = { accountUuid: 'u-1' };
    expect(ok.accountUuid).toBe('u-1');
  });

  it('AddAccountWithTokenFailedPayload supports reason codes', () => {
    const failed: AddAccountWithTokenFailedPayload = {
      reason: 'http_401',
      message: 'invalid token',
    };
    expect(failed.reason).toBe('http_401');
  });

  it('preserves backward compatibility — existing keys still present', () => {
    expect(MESSAGE_TYPES.READY).toBe('READY');
    expect(MESSAGE_TYPES.HANDSHAKE).toBe('HANDSHAKE');
    expect(MESSAGE_TYPES.SESSION_CHANGED).toBe('SESSION_CHANGED');
    expect(MESSAGE_TYPES.SWITCH_ACCOUNT).toBe('SWITCH_ACCOUNT');
  });
});
```

### Step 2 — Run, expect failure

```bash
npx vitest run tests/unit/lib/message-protocol.test.ts
```

### Step 3 — Add the three slugs + three payload interfaces

Edit `src/lib/message-protocol.ts`. Append to the `MESSAGE_TYPES` const (preserve existing order; insert the new trio after `ACCOUNT_ADDED` to keep account-related slugs grouped):

```ts
export const MESSAGE_TYPES = {
  // Handshake messages
  READY: 'READY',
  HANDSHAKE: 'HANDSHAKE',
  HANDSHAKE_ACK: 'HANDSHAKE_ACK',

  // Unified Action Protocol (new)
  ACTION_REQUEST: 'ACTION_REQUEST',
  URL_REDIRECT: 'URL_REDIRECT',

  // Account query messages (legacy - kept for backward compatibility)
  GET_ACCOUNTS: 'GET_ACCOUNTS',
  ACCOUNTS_DATA: 'ACCOUNTS_DATA',

  // Account action messages (legacy - kept for backward compatibility)
  SWITCH_ACCOUNT: 'SWITCH_ACCOUNT',
  ACCOUNT_SWITCHED: 'ACCOUNT_SWITCHED',
  ADD_ACCOUNT: 'ADD_ACCOUNT',
  ACCOUNT_ADDED: 'ACCOUNT_ADDED',

  // Cross-product handoff (Sharing protocol — F3/F4/A6)
  ADD_ACCOUNT_WITH_TOKEN: 'add-account-with-token',                  // host → iframe: push a pre-authenticated account
  ADD_ACCOUNT_WITH_TOKEN_OK: 'add-account-with-token-ok',            // iframe → host: cookie written, account appended
  ADD_ACCOUNT_WITH_TOKEN_FAILED: 'add-account-with-token-failed',    // iframe → host: backend rejected the session token

  MANAGE_ACCOUNT: 'MANAGE_ACCOUNT',
  UPDATE_AVATAR: 'UPDATE_AVATAR',
  LOGOUT_ACCOUNT: 'LOGOUT_ACCOUNT',
  ACCOUNT_LOGGED_OUT: 'ACCOUNT_LOGGED_OUT',
  LOGOUT_ALL: 'LOGOUT_ALL',
  ALL_LOGGED_OUT: 'ALL_LOGGED_OUT',

  // Interactive request messages (iframe → host)
  CONFIRM_REQUEST: 'CONFIRM_REQUEST',
  PROMPT_REQUEST: 'PROMPT_REQUEST',
  ALERT_REQUEST: 'ALERT_REQUEST',
  REQUEST_RESPONSE: 'REQUEST_RESPONSE',

  // Event notifications
  SESSION_CHANGED: 'SESSION_CHANGED',
  TOKEN_REFRESHED: 'TOKEN_REFRESHED',
  SIZE_CHANGED: 'SIZE_CHANGED',

  // Page context messages
  SET_PAGE_URL: 'SET_PAGE_URL',

  // Error messages
  ERROR: 'ERROR',
  UNAUTHORIZED: 'UNAUTHORIZED',
} as const;
```

Append the three new payload interfaces below the existing `ResizePayload` block (keeps Sharing types grouped at the bottom):

```ts
/**
 * Sharing protocol — request payload sent by `multiAccountAutoAdd` (F4) to the
 * auth-service iframe (A6). The iframe redeems the session token at its own
 * backend, writes the destination-scoped session cookie, and acks with
 * ADD_ACCOUNT_WITH_TOKEN_OK (or ..._FAILED).
 */
export interface AddAccountWithTokenPayload {
  /** UUID of the user being added — must match the user the session token was issued for. */
  accountUuid: string;
  /** Short-lived session token returned by the handoff exchange (F1). */
  sessionToken: string;
}

/**
 * Sharing protocol — iframe → host: cookie written and account appended.
 */
export interface AddAccountWithTokenOkPayload {
  accountUuid: string;
}

/**
 * Sharing protocol — iframe → host: redemption failed.
 *
 * `reason` is one of:
 * - `http_401` — session token invalid / expired (auth-service rejected)
 * - `http_4xx` / `http_5xx` — generic upstream error
 * - `network` — fetch to iframe's own backend failed
 * - `mismatch` — session token's user didn't match `accountUuid`
 */
export interface AddAccountWithTokenFailedPayload {
  reason: string;
  message?: string;
}
```

### Step 4 — Run + commit

```bash
npx vitest run tests/unit/lib/message-protocol.test.ts
# Expected: 7 passed.

npm run type-check

git add src/lib/message-protocol.ts tests/unit/lib/message-protocol.test.ts
git commit -m "feat(sharing): phase F3 — ADD_ACCOUNT_WITH_TOKEN message type

Add the request/ok/failed trio to MESSAGE_TYPES plus typed payload
interfaces. Pure additive change to the message-protocol registry —
no existing slug renamed or removed. Consumed by the auth-service
iframe handler (A6); sent by the F4 multiAccountAutoAdd primitive.

Phase: F3 of docs/plans/InterProductCommunication-2026-05-27/"
```
