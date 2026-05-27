# Phase F1 — Next iframe primitives + `<PrepEmbed>` component

**Repo:** `auth-service-nextjs`
**Depends on:** PHP D1 ships `data-prep-id` + postMessage protocol from the iframe

## Goal

A drop-in React component the orchestrator uses to render the peer's signing iframe AND react to its completion. Plus the postMessage type registry so the message-protocol audit stays consistent.

```tsx
<PrepEmbed
  prepResult={prep}                       // from Sharing::prepare on PHP side
  onSigned={({ prepId }) => promote()}    // fires after iframe acks
  onFailed={({ reason }) => showError()}
  height="600px"
/>
```

## Files

- **Modify:** `src/lib/message-protocol.ts` — add `PREP_SIGNED` / `PREP_SIGN_FAILED` message types + payload interfaces
- **Create:** `src/sharing/PrepEmbed.tsx`
- **Modify:** `src/sharing/index.ts` — export `PrepEmbed`
- **Test:** `tests/unit/sharing/PrepEmbed.test.tsx`
- **Test:** `tests/unit/lib/message-protocol-prep.test.ts`

## Steps

### Step 1 — Message protocol additions

```ts
// In src/lib/message-protocol.ts — add to MESSAGE_TYPES:
PREP_SIGNED:       'prep-signed',         // iframe → host: signature captured
PREP_SIGN_FAILED:  'prep-sign-failed',    // iframe → host: signature rejected
```

```ts
// Append payload interfaces near the bottom:
export interface PrepSignedPayload {
  prep_id: string;
}
export interface PrepSignFailedPayload {
  prep_id: string;
  error: string;
  message?: string;
}
```

(Note the iframe content posts these via `window.parent.postMessage({type: 'prep-signed', prep_id})` — see PHP D1's `AgreementSignHandler::render()`. The message types are wire-format strings, not the `MESSAGE_TYPES.*` JS enum.)

### Step 2 — PrepEmbed component

```tsx
// src/sharing/PrepEmbed.tsx
'use client';
import { useEffect, useRef, useState } from 'react';

export interface PrepResultLike {
  prepId: string;
  embedUrl: string;
  expiresAt?: string | null;
  status: string;
}

export interface PrepEmbedSignedEvent { prepId: string; }
export interface PrepEmbedFailedEvent { prepId: string; error: string; message?: string; }

export interface PrepEmbedProps {
  prepResult: PrepResultLike;
  onSigned?: (e: PrepEmbedSignedEvent) => void;
  onFailed?: (e: PrepEmbedFailedEvent) => void;
  /** Optional CSS height. Defaults to 600px. */
  height?: string | number;
  /** Optional CSS width. Defaults to 100%. */
  width?: string | number;
  /** Override the iframe `title` attr for a11y. Defaults to "Signing surface". */
  title?: string;
  /** Restrict accepted postMessage origins. Defaults to the embed URL's origin. */
  expectedOrigin?: string;
}

/**
 * Renders the peer's signing iframe + listens for the prep-signed / prep-sign-failed
 * messages. Pair with Sharing::prepare on the PHP backend:
 *
 *   const prep = await fetch('/api/prepare-portify-agreement').then(r => r.json());
 *   return <PrepEmbed prepResult={prep} onSigned={({prepId}) => promote(prepId)} />;
 */
export function PrepEmbed({
  prepResult,
  onSigned,
  onFailed,
  height = 600,
  width = '100%',
  title = 'Signing surface',
  expectedOrigin,
}: PrepEmbedProps) {
  const iframeRef = useRef<HTMLIFrameElement | null>(null);
  const [completed, setCompleted] = useState(false);

  // Compute the expected origin once from the embed URL
  const computedOrigin = (() => {
    try {
      return expectedOrigin ?? new URL(prepResult.embedUrl).origin;
    } catch {
      return expectedOrigin;
    }
  })();

  useEffect(() => {
    function onMessage(ev: MessageEvent) {
      if (completed) return;
      if (computedOrigin && ev.origin !== computedOrigin) return;
      const data = ev.data;
      if (!data || typeof data !== 'object') return;
      if (data.prep_id !== prepResult.prepId) return;

      if (data.type === 'prep-signed') {
        setCompleted(true);
        onSigned?.({ prepId: prepResult.prepId });
      } else if (data.type === 'prep-sign-failed') {
        setCompleted(true);
        onFailed?.({
          prepId: prepResult.prepId,
          error: typeof data.error === 'string' ? data.error : 'unknown',
          message: typeof data.message === 'string' ? data.message : undefined,
        });
      }
    }
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [prepResult.prepId, computedOrigin, completed, onSigned, onFailed]);

  return (
    <iframe
      ref={iframeRef}
      src={prepResult.embedUrl}
      title={title}
      style={{
        width: typeof width === 'number' ? `${width}px` : width,
        height: typeof height === 'number' ? `${height}px` : height,
        border: 0,
      }}
      sandbox="allow-scripts allow-same-origin allow-forms"
    />
  );
}
```

### Step 3 — Barrel update

```ts
// src/sharing/index.ts — append:
export { PrepEmbed } from './PrepEmbed';
export type {
  PrepEmbedProps,
  PrepEmbedSignedEvent,
  PrepEmbedFailedEvent,
  PrepResultLike,
} from './PrepEmbed';
```

### Step 4 — Failing tests

```ts
// tests/unit/lib/message-protocol-prep.test.ts
import { describe, expect, it } from 'vitest';
import {
  MESSAGE_TYPES,
  type PrepSignedPayload,
  type PrepSignFailedPayload,
} from '../../../src/lib/message-protocol';

describe('Prep message protocol additions', () => {
  it('PREP_SIGNED uses the wire slug', () => {
    expect(MESSAGE_TYPES.PREP_SIGNED).toBe('prep-signed');
  });

  it('PREP_SIGN_FAILED uses the wire slug', () => {
    expect(MESSAGE_TYPES.PREP_SIGN_FAILED).toBe('prep-sign-failed');
  });

  it('payload types compile', () => {
    const ok: PrepSignedPayload = { prep_id: 'p-1' };
    const fail: PrepSignFailedPayload = { prep_id: 'p-1', error: 'bad_signature', message: 'mismatched name' };
    expect(ok.prep_id).toBe('p-1');
    expect(fail.error).toBe('bad_signature');
  });
});
```

```tsx
// tests/unit/sharing/PrepEmbed.test.tsx
// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { PrepEmbed } from '../../../src/sharing/PrepEmbed';

const PREP = {
  prepId: 'p-1',
  embedUrl: 'https://portify.test/sharing/embed/agreement_sign/p-1',
  status: 'prepared' as const,
};

describe('PrepEmbed', () => {
  let onSigned: ReturnType<typeof vi.fn>;
  let onFailed: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    onSigned = vi.fn();
    onFailed = vi.fn();
  });

  afterEach(() => { vi.restoreAllMocks(); });

  it('renders an iframe with the embed URL', () => {
    render(<PrepEmbed prepResult={PREP} title="Sign here" />);
    const iframe = screen.getByTitle('Sign here') as HTMLIFrameElement;
    expect(iframe.src).toBe(PREP.embedUrl);
  });

  it('fires onSigned when a prep-signed message arrives from the right origin and prep_id', () => {
    render(<PrepEmbed prepResult={PREP} onSigned={onSigned} onFailed={onFailed} />);
    act(() => {
      window.dispatchEvent(new MessageEvent('message', {
        origin: 'https://portify.test',
        data: { type: 'prep-signed', prep_id: 'p-1' },
      }));
    });
    expect(onSigned).toHaveBeenCalledWith({ prepId: 'p-1' });
    expect(onFailed).not.toHaveBeenCalled();
  });

  it('fires onFailed on prep-sign-failed', () => {
    render(<PrepEmbed prepResult={PREP} onSigned={onSigned} onFailed={onFailed} />);
    act(() => {
      window.dispatchEvent(new MessageEvent('message', {
        origin: 'https://portify.test',
        data: { type: 'prep-sign-failed', prep_id: 'p-1', error: 'mismatch', message: 'name mismatch' },
      }));
    });
    expect(onFailed).toHaveBeenCalledWith({ prepId: 'p-1', error: 'mismatch', message: 'name mismatch' });
    expect(onSigned).not.toHaveBeenCalled();
  });

  it('ignores messages from the wrong origin', () => {
    render(<PrepEmbed prepResult={PREP} onSigned={onSigned} />);
    act(() => {
      window.dispatchEvent(new MessageEvent('message', {
        origin: 'https://attacker.test',
        data: { type: 'prep-signed', prep_id: 'p-1' },
      }));
    });
    expect(onSigned).not.toHaveBeenCalled();
  });

  it('ignores messages with a different prep_id', () => {
    render(<PrepEmbed prepResult={PREP} onSigned={onSigned} />);
    act(() => {
      window.dispatchEvent(new MessageEvent('message', {
        origin: 'https://portify.test',
        data: { type: 'prep-signed', prep_id: 'p-OTHER' },
      }));
    });
    expect(onSigned).not.toHaveBeenCalled();
  });

  it('fires onSigned only once even on duplicate messages', () => {
    render(<PrepEmbed prepResult={PREP} onSigned={onSigned} />);
    act(() => {
      window.dispatchEvent(new MessageEvent('message', {
        origin: 'https://portify.test',
        data: { type: 'prep-signed', prep_id: 'p-1' },
      }));
      window.dispatchEvent(new MessageEvent('message', {
        origin: 'https://portify.test',
        data: { type: 'prep-signed', prep_id: 'p-1' },
      }));
    });
    expect(onSigned).toHaveBeenCalledTimes(1);
  });
});
```

### Step 5 — Run + commit

```bash
cd C:\Users\benpl\Documents\GitHub\auth-service-nextjs
npx vitest run tests/unit/lib/message-protocol-prep.test.ts tests/unit/sharing/PrepEmbed.test.tsx
# Expected: 3 + 6 passed.

npm run type-check
git add src/lib/message-protocol.ts src/sharing/PrepEmbed.tsx src/sharing/index.ts \
        tests/unit/lib/message-protocol-prep.test.ts tests/unit/sharing/PrepEmbed.test.tsx
git commit -m "feat(sharing): phase F1 — <PrepEmbed/> + PREP_SIGNED message types

Drop-in React component for the orchestrator side of Prep-Sign-Promote.
Renders the peer's signing iframe + listens for prep-signed/prep-sign-
failed messages (origin-validated against the embed URL, prep_id-scoped,
single-fire). Pair with await Sharing::prepare on PHP, await promote()
on onSigned callback.

Phase: F1 of docs/plans/PrepSignPromote-2026-05-27/"
```

## Verification checklist

- [ ] PrepEmbed iframe inherits `sandbox="allow-scripts allow-same-origin allow-forms"`
- [ ] Origin check defaults to `new URL(prepResult.embedUrl).origin`; never accepts wildcard
- [ ] Duplicate messages don't double-fire callbacks (single completion latch)
- [ ] prep_id mismatch silently ignored (other PrepEmbed instances on the page may exist)
- [ ] Component renders SSR-safely (`'use client'` at the top; no window access during render)
