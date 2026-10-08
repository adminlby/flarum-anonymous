import { describe, it, expect, vi } from 'vitest';
import { chooseIdentity, loadWithIdentity, replyKind } from '../src/forum/identity';
import { originalAnonymousAuthor } from '../src/forum/editIdentity';

describe('identity selection before core composer load', () => {
  it('waits for the choice before opening the composer', async () => {
    let resolve!: (value: boolean) => void;
    const choice = new Promise<boolean>((r) => {resolve = r;});
    const load = vi.fn(async (attrs) => attrs);
    const pending = loadWithIdentity(() => choice, load, {title: 'draft'});
    expect(load).not.toHaveBeenCalled();
    resolve(true);
    await expect(pending).resolves.toEqual({title: 'draft', isAnonymous: true});
    expect(load).toHaveBeenCalledTimes(1);
  });
  it('cancellation never opens a composer', async () => {
    const load = vi.fn();
    await expect(loadWithIdentity(() => Promise.reject('cancel'), load, {})).rejects.toBe('cancel');
    expect(load).not.toHaveBeenCalled();
  });
  it.each([['ask', true], ['real', false], ['anonymous', true]] as const)('honors %s mode', async (mode, value) => {
    const modal = vi.fn(async () => true);
    expect(await chooseIdentity(mode, true, modal)).toBe(value);
    expect(modal).toHaveBeenCalledTimes(mode === 'ask' ? 1 : 0);
  });
  it('a disabled type cannot select anonymous, including default anonymous mode', async () => {
    expect(await chooseIdentity('anonymous', false, async () => true)).toBe(false);
  });
  it.each(['> quoted text', 'hello\n> quoted text', '@"Anonymous user"#p12', '@"Name"#12', '[quote]quoted text[/quote]'])('recognizes quote input %s', (content) => {
    expect(replyKind(content)).toBe('quotes');
  });
  it('regular replies use the reply setting', () => {expect(replyKind('ordinary reply')).toBe('replies');});
});

describe('original anonymous authors cannot select a named editing identity', () => {
  it.each([[true, true, true], [true, false, false], [false, true, false], [false, false, false]])(
    'locks an anonymous post=%s owned by the viewer=%s to anonymity=%s', (anonymous, mine, locked) => {
      expect(originalAnonymousAuthor({attribute: key => key === 'isAnonymous' ? anonymous : mine})).toBe(locked);
    }
  );
});
