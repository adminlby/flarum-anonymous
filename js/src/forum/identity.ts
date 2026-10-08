export type Kind = 'discussions' | 'replies' | 'quotes' | 'other';
export type Mode = 'ask' | 'real' | 'anonymous';

export function replyKind(content: string): Kind {
  return /(^|\n)\s*>|@"[^\n]+"#p?\d+|\[quote\b/iu.test(content) ? 'quotes' : 'replies';
}

export async function chooseIdentity(mode: Mode, allowed: boolean, choose: () => Promise<boolean>): Promise<boolean> {
  if (!allowed) return false;
  return mode === 'ask' ? choose() : mode === 'anonymous';
}

// Kept independent of rendering so cancellation and pre-composer ordering can
// be exercised with real promises in tests.
export async function loadWithIdentity<T>(
  choose: () => Promise<boolean>,
  load: (attrs: Record<string, unknown>) => Promise<T>,
  attrs: Record<string, unknown>
): Promise<T> {
  const isAnonymous = await choose();
  return load({ ...attrs, isAnonymous });
}
