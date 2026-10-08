const {test, expect} = require('../../js/node_modules/@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const site = process.env.ANONYMOUS_E2E_SITE || path.join(__dirname, '../../.workbench/site');
const tokens = JSON.parse(fs.readFileSync(path.join(site, 'e2e-tokens.json'), 'utf8'));
const defaults = Object.fromEntries(['enabled', 'discussions', 'replies', 'quotes', 'other', 'showOwn'].map(key => ['adminlby-anonymous.' + key, '1']));
defaults['adminlby-anonymous.editHistoryChoice'] = '0';
defaults.default_locale = 'en';
const errors = new WeakMap();

async function settings(request, values = {}) {
  const response = await request.post('/api/settings', {headers: {Authorization: 'Token ' + tokens.admin}, data: {...defaults, 'adminlby-anonymous.mode': 'ask', ...values}});
  expect(response.status()).toBe(204);
  // This isolated site has only the English pack. Register the requested
  // default before rebuilding its assets, including a return to English,
  // so an older test language bundle is never mistaken for the new build.
  const cleared = await request.delete('/api/cache', {headers: {Authorization: 'Token ' + tokens.admin}});
  expect(cleared.status()).toBe(204);
}
async function login(page, username = 'reader') {
  await page.goto('/');
  await page.getByRole('button', {name: 'Log In', exact: true}).first().click();
  await page.locator('input[name="identification"]').fill(username);
  await page.locator('input[name="password"]').fill('anonymous-browser-test-only');
  await page.locator('.LogInModal button[type="submit"]').click();
  await expect(page.locator('.LogInModal')).toHaveCount(0);
  await page.waitForFunction(() => window.app?.session?.user?.username() === undefined ? false : !!window.app.session.user);
}
async function chooseAnonymous(page) {
  await expect(page.locator('.AnonymousIdentityModal')).toBeVisible();
  await page.locator('.AnonymousIdentityModal').getByRole('button', {name: 'Post anonymously', exact: true}).click();
  await expect(page.locator('.ComposerBody')).toBeVisible();
}
async function created(responsePromise) {
  const response = await responsePromise;
  const json = await response.json();
  expect(response.status(), JSON.stringify(json)).toBe(201);
  return json;
}
async function submitDiscussion(page) {
  await page.getByRole('button', {name: 'Post Discussion', exact: true}).click();
  await page.locator('.TagSelectionModal').getByText('General', {exact: true}).click();
  await page.locator('.TagSelectionModal').getByRole('button', {name: 'OK', exact: true}).click();
}
function assertPrivate(json) {
  expect(JSON.stringify(json)).not.toContain('secret_author_829');
  for (const resource of [json.data, ...(json.included || [])].flat()) {
    if (resource.type === 'users') expect(resource.id).not.toBe('2');
    if (resource.type === 'posts' && resource.attributes?.isAnonymous) expect(resource.relationships?.user?.data ?? null).toBeNull();
  }
}

test.beforeEach(async ({page, request}) => {
  await settings(request);
  errors.set(page, []);
  page.on('pageerror', error => errors.get(page).push(error.message));
});
test.afterEach(async ({page}) => {expect(errors.get(page)).toEqual([]);});

test('discussion identity is chosen before the composer and persists through real submission', async ({page}) => {
  await login(page);
  await page.getByRole('button', {name: 'Start a Discussion', exact: true}).click();
  await expect(page.locator('.AnonymousIdentityModal')).toBeVisible();
  await expect(page.locator('.ComposerBody')).toHaveCount(0);
  await chooseAnonymous(page);
  await expect(page.locator('.ComposerBody-avatar.AnonymousAvatar')).toBeVisible();
  await page.getByRole('textbox', {name: 'Discussion Title', exact: true}).fill('Browser anonymous discussion');
  await page.locator('.TextEditor textarea').fill('Browser anonymous discussion content');
  const response = page.waitForResponse(response => response.url().endsWith('/api/discussions') && response.request().method() === 'POST');
  await submitDiscussion(page);
  const json = await created(response);
  expect(json.data.attributes.isAnonymous).toBe(true);
  expect(json.data.relationships.user?.data ?? null).toBeNull();
  await expect(page.locator('.PostUser--anonymous').first()).toBeVisible();
  await expect(page.getByText('This is your anonymous post').first()).toBeVisible();
});

test('ordinary reply asks before opening the composer and sends an anonymous API request', async ({page}) => {
  await login(page, 'secret_author_829');
  await page.goto('/d/2-named-fixture');
  await page.getByRole('navigation').getByRole('button', {name: 'Reply', exact: true}).click();
  await expect(page.locator('.ComposerBody')).toHaveCount(0);
  await chooseAnonymous(page);
  await page.locator('.TextEditor textarea').fill('Browser anonymous ordinary reply');
  const response = page.waitForResponse(response => response.url().endsWith('/api/posts') && response.request().method() === 'POST');
  await page.getByRole('button', {name: 'Post Reply', exact: true}).click();
  const json = await created(response);
  expect(json.data.attributes.isAnonymous).toBe(true);
  assertPrivate(json);
});

test('post reply action selects the quote identity while ordinary anonymous replies are disabled', async ({page, request}) => {
  await settings(request, {'adminlby-anonymous.replies': '0'});
  await login(page, 'quote_reader');
  await page.goto('/d/1-anonymous-fixture');
  await page.locator('.Post-actions .item-reply button').first().click();
  await expect(page.locator('.ComposerBody')).toHaveCount(0);
  await chooseAnonymous(page);
  await expect(page.locator('.TextEditor textarea')).toHaveValue(/@"Anonymous user"#p1/);
  await page.locator('.TextEditor textarea').fill('@"Anonymous user"#p1 Browser anonymous quoted reply');
  const response = page.waitForResponse(response => response.url().endsWith('/api/posts') && response.request().method() === 'POST');
  await page.getByRole('button', {name: 'Post Reply', exact: true}).click();
  expect((await created(response)).data.attributes.isAnonymous).toBe(true);
});

test('selected-text quote button waits for identity before opening the composer', async ({page}) => {
  await login(page);
  await page.goto('/d/1-anonymous-fixture');
  await page.locator('.Post-body').first().selectText();
  await page.locator('.Post-body').first().dispatchEvent('mouseup', {clientX: 300, clientY: 300});
  await page.locator('.PostQuoteButton').first().click();
  await expect(page.locator('.AnonymousIdentityModal')).toBeVisible();
  await expect(page.locator('.ComposerBody')).toHaveCount(0);
  await chooseAnonymous(page);
  await expect(page.locator('.TextEditor textarea')).toHaveValue(/> @"Anonymous user"#p1 Anonymous initial content/);
});

for (const [mode, pressed] of [['real', 'false'], ['anonymous', 'true']]) {
  test(`default ${mode} mode opens with the configured identity and allows switching`, async ({page, request}) => {
    await settings(request, {'adminlby-anonymous.mode': mode});
    // Each submission uses a separate actor so core's real-user flood control
    // remains enabled and tests do not depend on browser execution speed.
    await login(page, mode === 'real' ? 'admin' : 'toggle_reader');
    await page.getByRole('button', {name: 'Start a Discussion', exact: true}).click();
    await expect(page.locator('.ComposerBody')).toBeVisible();
    await expect(page.locator('.AnonymousIdentityModal')).toHaveCount(0);
    await expect(page.locator('.AnonymousIdentityToggle')).toHaveAttribute('aria-pressed', pressed);
    await page.locator('.AnonymousIdentityToggle').click();
    await expect(page.locator('.AnonymousIdentityToggle')).toHaveAttribute('aria-pressed', pressed === 'true' ? 'false' : 'true');
    await page.getByRole('textbox', {name: 'Discussion Title', exact: true}).fill('Browser identity toggle ' + mode);
    await page.locator('.TextEditor textarea').fill('Submission after changing the default identity');
    const response = page.waitForResponse(response => response.url().endsWith('/api/discussions') && response.request().method() === 'POST');
    await submitDiscussion(page);
    const json = await created(response);
    expect(json.data.attributes.isAnonymous).toBe(mode === 'real');
    expect(json.data.relationships.user?.data?.id ?? null).toBe(mode === 'real' ? null : '5');
  });
}

test('admins see anonymous posts until they explicitly reveal and the normal store remains anonymous', async ({page}) => {
  await login(page, 'admin');
  await page.goto('/d/1-anonymous-fixture');
  await expect(page.locator('.PostUser--anonymous').first()).toContainText('Anonymous user');
  await expect(page.locator('.PostStream')).not.toContainText('secret_author_829');
  await page.locator('.Post-controls .Dropdown-toggle').first().click();
  await page.getByRole('button', {name: 'View real author', exact: true}).first().click();
  await expect(page.locator('.AnonymousRevealModal')).not.toContainText('secret_author_829');
  await page.locator('.AnonymousRevealModal').getByRole('button', {name: 'View real author', exact: true}).click();
  await expect(page.locator('.AnonymousRevealModal')).toContainText('secret_author_829');
  await expect(page.locator('.AnonymousRevealModal')).toContainText('2');
  const user = await page.evaluate(() => window.app.store.getById('posts', '1').user());
  expect(user).toBe(false);
  await page.keyboard.press('Escape');
  await expect(page.locator('.PostUser--anonymous').first()).toContainText('Anonymous user');
});

test('guest SSR, preload, nested JSON API and profile author filters do not reveal identities', async ({page, request}) => {
  const response = await page.goto('/d/1-anonymous-fixture');
  expect(await response.text()).not.toContain('secret_author_829');
  await expect(page.locator('.PostUser--anonymous').first()).toContainText('Anonymous user');
  const api = await request.get('/api/discussions/1?include=user,lastPostedUser,firstPost.user,lastPost.user');
  assertPrivate(await api.json());
  const profilePosts = await request.get('/api/posts?filter[author]=secret_author_829');
  expect((await profilePosts.json()).data).toEqual([]);
});

test('admin settings expose every type switch, mode and reveal permission', async ({page}) => {
  await login(page, 'admin');
  await page.goto('/admin#/extension/adminlby-anonymous');
  await expect(page.getByText('Convert existing posts between anonymous and named', {exact: true})).toBeVisible();
  for (const text of ['Enable anonymous posting', 'Allow anonymous discussions', 'Allow anonymous replies', 'Allow anonymous quote replies',
    'Allow anonymous submissions from other post types', 'Posting identity interaction', 'Reveal anonymous authors']) {
    await expect(page.getByText(text, {exact: true}).first()).toBeVisible();
  }
});

async function convertFirstPost(page, menuLabel, confirmLabel) {
  await page.locator('.Post-controls .Dropdown-toggle').first().click();
  await page.getByRole('button', {name: menuLabel, exact: true}).click();
  await expect(page.locator('.AnonymousChangeIdentityModal')).toBeVisible();
  const response = page.waitForResponse(response => response.url().includes('/api/anonymous/posts/') &&
    response.url().endsWith('/identity') && response.request().method() === 'POST');
  const reloaded = page.waitForEvent('framenavigated', {predicate: frame => frame === page.mainFrame()});
  await page.locator('.AnonymousChangeIdentityModal').getByRole('button', {name: confirmLabel, exact: true}).click();
  const result = await response;
  expect(result.status()).toBe(200);
  await reloaded;
  await expect(page.locator('.PostUser').first()).toBeVisible();
  await expect(page.locator('.AnonymousChangeIdentityModal')).toHaveCount(0);
  const id = new URL(result.url()).pathname.split('/').at(-2);
  const refreshed = await page.request.get('/api/posts/' + id);
  expect(refreshed.status()).toBe(200);
  return refreshed.json();
}

test('moderator converts a named first post both ways and refreshed API relationships follow it', async ({page, request}) => {
  await login(page, 'admin');
  await page.goto('/d/2-named-fixture');
  await expect(page.locator('.PostUser').first()).toContainText('reader');
  const anonymous = await convertFirstPost(page, 'Convert to anonymous', 'Confirm anonymous conversion');
  expect(anonymous.data.attributes.isAnonymous).toBe(true);
  await expect(page.locator('.PostUser--anonymous').first()).toContainText('Anonymous user');
  const api = await request.get('/api/discussions/2?include=user,firstPost.user,lastPostedUser');
  const json = await api.json();
  expect(json.data.relationships.user.data).toBeNull();
  expect(json.included.find(row => row.type === 'posts' && row.id === '3').relationships.user.data).toBeNull();
  const named = await convertFirstPost(page, "Show the author's identity", 'Confirm public identity');
  expect(named.data.attributes.isAnonymous).toBe(false);
  await expect(page.locator('.PostUser').first()).toContainText('reader');
  const restored = await (await request.get('/api/posts/3')).json();
  expect(restored.data.relationships.user.data.id).toBe('3');
});

test('identity conversion requires its own permission and normal browser CSRF protection', async ({page, request}) => {
  await login(page, 'reader');
  await page.goto('/d/2-named-fixture');
  await page.locator('.Post-controls .Dropdown-toggle').first().click();
  await expect(page.getByRole('button', {name: 'Convert to anonymous', exact: true})).toHaveCount(0);
  const denied = await request.post('/api/anonymous/posts/3/identity', {headers: {Authorization: 'Token ' + tokens.reader},
    data: {data: {attributes: {isAnonymous: true}}}});
  expect(denied.status()).toBe(403);
  const adminContext = await page.context().browser().newContext({baseURL: new URL(page.url()).origin});
  const admin = await adminContext.newPage();
  admin.on('pageerror', error => errors.get(page).push(error.message));
  await login(admin, 'admin');
  const noCsrf = await admin.evaluate(async () => {
    const response = await fetch('/api/anonymous/posts/3/identity', {method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', Accept: 'application/vnd.api+json'}, body: JSON.stringify({data: {attributes: {isAnonymous: true}}})});
    return response.status;
  });
  expect([400, 403, 419]).toContain(noCsrf);
  await adminContext.close();
});

test('poll voters hide an anonymous post author and restore their named vote after conversion', async ({page}) => {
  await login(page, 'admin');
  await page.goto('/d/1-anonymous-fixture');
  await page.locator('.PollHeading-voters').first().click();
  await expect(page.locator('.VotesModal .AnonymousPollVote')).toContainText('Anonymous user');
  await expect(page.locator('.VotesModal')).not.toContainText('secret_author_829');
  await expect(page.locator('.VotesModal')).toContainText('reader');
  await page.locator('.VotesModal .Modal-close').click();
  await convertFirstPost(page, "Show the author's identity", 'Confirm public identity');
  await page.locator('.PollHeading-voters').first().click();
  await expect(page.locator('.VotesModal')).toContainText('secret_author_829');
  await page.locator('.VotesModal .Modal-close').click();
  await convertFirstPost(page, 'Convert to anonymous', 'Confirm anonymous conversion');
  await expect(page.locator('.PostUser--anonymous').first()).toContainText('Anonymous user');
});

for (const [locale, menu, title, confirm, namedMenu, namedConfirm] of [
  ['zh-Hans', '转为匿名', '将此帖转为匿名', '确认转为匿名', '公开作者身份', '确认公开身份'],
  ['zh-Hant', '轉為匿名', '將此帖轉為匿名', '確認轉為匿名', '公開作者身份', '確認公開身份'],
]) {
  test(`identity conversion controls use ${locale} locale keys`, async ({page, request}) => {
    await settings(request, {default_locale: locale});
    await login(page, 'admin');
    await page.goto('/d/2-named-fixture');
    await page.locator('.Post-controls .Dropdown-toggle').first().click();
    await page.getByRole('button', {name: menu, exact: true}).click();
    await expect(page.locator('.AnonymousChangeIdentityModal .Modal-header')).toContainText(title);
    await page.locator('.AnonymousChangeIdentityModal .Modal-close').click();
    await convertFirstPost(page, menu, confirm);
    await convertFirstPost(page, namedMenu, namedConfirm);
    await expect(page.locator('.PostUser').first()).toContainText('reader');
    await settings(request);
  });
}

test('cancelled identity choice leaves the composer closed', async ({page}) => {
  await login(page);
  await page.getByRole('button', {name: 'Start a Discussion', exact: true}).click();
  await expect(page.locator('.AnonymousIdentityModal')).toBeVisible();
  await expect(page.locator('.AnonymousIdentityModal.in')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('.AnonymousIdentityModal')).toHaveCount(0);
  await expect(page.locator('.ComposerBody')).toHaveCount(0);
});

test('choosing show identity opens the composer with the real author and preserves the choice', async ({page}) => {
  await login(page, 'named_reader');
  await page.getByRole('button', {name: 'Start a Discussion', exact: true}).click();
  await expect(page.locator('.ComposerBody')).toHaveCount(0);
  await page.locator('.AnonymousIdentityModal').getByRole('button', {name: 'Show identity', exact: true}).click();
  await expect(page.locator('.AnonymousIdentityToggle')).toHaveAttribute('aria-pressed', 'false');
  await page.getByRole('textbox', {name: 'Discussion Title', exact: true}).fill('Browser explicit named choice');
  await page.locator('.TextEditor textarea').fill('Explicitly show my identity');
  const response = page.waitForResponse(response => response.url().endsWith('/api/discussions') && response.request().method() === 'POST');
  await submitDiscussion(page);
  const json = await created(response);
  expect(json.data.attributes.isAnonymous).toBe(false);
  expect(json.data.relationships.user.data.id).toBe('6');
});

test('editing anonymous posts skips identity selection and keeps anonymity with the own-post hint disabled', async ({page, request}) => {
  await settings(request, {'adminlby-anonymous.showOwn': '0'});
  await login(page, 'secret_author_829');
  await page.goto('/d/1-anonymous-fixture');
  await expect(page.getByText('This is your anonymous post', {exact: true})).toHaveCount(0);
  await page.locator('.Post-controls .Dropdown-toggle').first().click();
  await page.getByRole('button', {name: 'Edit', exact: true}).first().click();
  await expect(page.locator('.ComposerBody')).toBeVisible();
  await expect(page.locator('.AnonymousIdentityModal')).toHaveCount(0);
  await expect(page.locator('.AnonymousIdentityToggle')).toHaveCount(0);
  await page.locator('.TextEditor textarea').fill('Anonymous initial content, edited by its author');
  const response = page.waitForResponse(response => response.url().includes('/api/posts/1') && (
    response.request().method() === 'PATCH' || response.request().headers()['x-http-method-override'] === 'PATCH'
  ));
  await page.getByRole('button', {name: 'Save Changes', exact: true}).click();
  const json = await (await response).json();
  expect(json.data.attributes.isAnonymous).toBe(true);
  expect(json.data.attributes.isMine).toBe(true);
  // The authenticated session itself can contain the current user. The post
  // and its related author/editor resources must still be redacted.
  expect(json.data.relationships.user?.data ?? null).toBeNull();
  expect(json.data.relationships.editedUser?.data ?? null).toBeNull();
  await expect(page.locator('.PostUser--anonymous').first()).toContainText('Anonymous user');
});

test('explicit author reveal requires a valid CSRF token for a logged-in browser session', async ({page}) => {
  await login(page, 'admin');
  const result = await page.evaluate(async () => {
    const response = await fetch('/api/anonymous/posts/1/reveal', {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/vnd.api+json'}});
    return {status: response.status, text: await response.text()};
  });
  expect([400, 403, 419]).toContain(result.status);
  expect(result.text).not.toContain('secret_author_829');
});

test('automatic rename events display an anonymous icon and name rather than a deleted user', async ({page, request}) => {
  await settings(request, {'allow_renaming': '-1'});
  const response = await request.patch('/api/discussions/1', {headers: {Authorization: 'Token ' + tokens.author}, data: {
    data: {type: 'discussions', id: '1', attributes: {title: 'Anonymous renamed fixture'}},
  }});
  expect(response.status()).toBe(200);
  assertPrivate(await response.json());
  await login(page);
  await page.goto('/d/1');
  const event = page.locator('.DiscussionRenamedPost').first();
  await expect(event.locator('.EventPost-info')).toContainText('Anonymous user');
  await expect(event.locator('.EventPost-info')).not.toContainText('Deleted user');
  await expect(event.locator('.EventPost-icon.AnonymousAvatar .fa-user-secret')).toBeVisible();
});

async function editFirstPost(page, slug) {
  await page.goto('/d/' + slug);
  await page.locator('.Post-controls .Dropdown-toggle').first().click();
  await page.getByRole('button', {name: 'Edit', exact: true}).first().click();
}
async function saveEdit(page, id, content) {
  await page.locator('.TextEditor textarea').fill(content);
  const pending = page.waitForResponse(response => response.url().includes('/api/posts/' + id) && (
    response.request().method() === 'PATCH' || response.request().headers()['x-http-method-override'] === 'PATCH'
  ));
  await page.getByRole('button', {name: 'Save Changes', exact: true}).click();
  const response = await pending;
  expect(response.status()).toBe(200);
  return response.json();
}

test('anonymous original authors have a locked editing identity and private Diff history after refresh', async ({page, request}) => {
  await settings(request, {'adminlby-anonymous.editHistoryChoice': '1'});
  await login(page, 'secret_author_829');
  await editFirstPost(page, '1');
  await expect(page.locator('.AnonymousIdentityModal')).toHaveCount(0);
  await expect(page.locator('.AnonymousEditIdentityToggle')).toBeDisabled();
  await expect(page.locator('.AnonymousEditIdentityToggle')).toHaveText('Original anonymous author: always anonymous');
  const json = await saveEdit(page, 1, 'Original anonymous author edited with identity permanently locked');
  expect(json.data.attributes.lastEditIsAnonymous).toBe(true);
  await page.reload();
  await page.locator('.DiffDropdown .Dropdown-toggle').first().click();
  const revisions = page.locator('.DiffDropdown .ParentDiff button');
  await expect(revisions.first()).toContainText('Anonymous user');
  await expect(revisions.first().locator('.AnonymousAvatar')).toBeVisible();
  await expect(page.locator('.DiffDropdown')).not.toContainText('secret_author_829');
  await revisions.first().click();
  await expect(page.locator('.DiffModal .Modal-header')).toContainText('Anonymous user');
  await expect(page.locator('.DiffModal .Modal-header')).toContainText('This is your anonymous edit');
  const history = await request.get('/api/diff?filter[post_id]=1', {headers: {Authorization: 'Token ' + tokens.reader}});
  assertPrivate(await history.json());
});

test('named posts allow an anonymous editor without changing the original post identity', async ({page, request}) => {
  await settings(request, {'adminlby-anonymous.editHistoryChoice': '1'});
  await login(page, 'admin');
  await editFirstPost(page, '2');
  await expect(page.locator('.ComposerBody')).toHaveCount(0);
  await expect(page.locator('.AnonymousIdentityModal')).toContainText('Choose your editing identity');
  await page.locator('.AnonymousIdentityModal').getByRole('button', {name: 'Edit anonymously', exact: true}).click();
  await expect(page.locator('.AnonymousEditIdentityToggle')).toHaveAttribute('aria-pressed', 'true');
  const json = await saveEdit(page, 3, 'Named post with an anonymous editing account');
  expect(json.data.attributes.isAnonymous).toBe(false);
  expect(json.data.attributes.lastEditIsAnonymous).toBe(true);
  expect(json.data.relationships.user.data.id).toBe('3');
  expect(json.data.relationships.editedUser?.data ?? null).toBeNull();
  await page.reload();
  await page.locator('.DiffDropdown .Dropdown-toggle').first().click();
  await expect(page.locator('.DiffDropdown .ParentDiff button').first()).toContainText('Anonymous user');
  await expect(page.locator('.DiffDropdown')).not.toContainText('admin');
});

test('an editor can choose named identity on an anonymous post while its original author remains hidden', async ({page, request}) => {
  await settings(request, {'adminlby-anonymous.editHistoryChoice': '1'});
  await login(page, 'admin');
  await editFirstPost(page, '1');
  await page.locator('.AnonymousIdentityModal').getByRole('button', {name: 'Show identity', exact: true}).click();
  await expect(page.locator('.AnonymousEditIdentityToggle')).toHaveAttribute('aria-pressed', 'false');
  const json = await saveEdit(page, 1, 'Administrator chose a named edit of an anonymous post');
  expect(json.data.attributes.isAnonymous).toBe(true);
  await page.reload();
  await page.locator('.DiffDropdown .Dropdown-toggle').first().click();
  await expect(page.locator('.DiffDropdown .ParentDiff button').first()).toContainText('admin');
  await expect(page.locator('.DiffDropdown')).not.toContainText('secret_author_829');
  const history = await request.get('/api/diff?filter[post_id]=1', {headers: {Authorization: 'Token ' + tokens.reader}});
  assertPrivate(await history.json());
});

test('moderators reveal an anonymous editor explicitly without filling the history actor relationship', async ({page, request}) => {
  await settings(request, {'adminlby-anonymous.editHistoryChoice': '1'});
  const response = await request.patch('/api/posts/3', {headers: {Authorization: 'Token ' + tokens.admin}, data: {
    data: {type: 'posts', id: '3', attributes: {content: 'Anonymous revision for editor reveal', editHistoryAnonymous: true}},
  }});
  expect(response.status()).toBe(200);
  await login(page, 'admin');
  await page.goto('/d/2');
  await page.locator('.DiffDropdown .Dropdown-toggle').first().click();
  await page.locator('.DiffDropdown .ParentDiff button').first().click();
  await expect(page.locator('.DiffModal .Modal-header')).toContainText('Anonymous user');
  await page.getByRole('button', {name: 'View real editor', exact: true}).click();
  await page.locator('.AnonymousRevealModal').getByRole('button', {name: 'View real editor', exact: true}).click();
  await expect(page.locator('.AnonymousRevealModal')).toContainText('admin');
  expect(await page.evaluate(() => Object.values(window.app.store.all('diff')).filter(r => r.attribute('isAnonymous')).every(r => !r.actor()))).toBe(true);
});

test('editor reveal rejects a browser request without CSRF and admin setting requires Diff', async ({page}) => {
  await login(page, 'admin');
  const result = await page.evaluate(async () => {
    const response = await fetch('/api/anonymous/diff/1/reveal', {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/vnd.api+json'}});
    return {status: response.status, text: await response.text()};
  });
  expect([400, 403, 419]).toContain(result.status);
  expect(result.text).not.toContain('secret_author_829');
  await page.goto('/admin#/extension/adminlby-anonymous');
  await expect(page.getByText('Allow choosing the identity of each edit history entry', {exact: true})).toBeVisible();
  await expect(page.getByText('Requires Diff 2.0.0. This choice only affects new edit history entries. Original anonymous authors always remain anonymous.', {exact: true})).toBeVisible();
});

for (const [locale, name, title, edit, settingLabel] of [
  ['zh-Hans', '匿名用户', '选择本次编辑记录的身份', '匿名编辑', '允许选择每条编辑记录的身份'],
  ['zh-Hant', '匿名使用者', '選擇本次編輯記錄的身分', '匿名編輯', '允許選擇每條編輯記錄的身分'],
]) {
  test(`anonymous editing UI and history render ${locale} locale keys`, async ({page, request}) => {
    await settings(request, {default_locale: locale, 'adminlby-anonymous.editHistoryChoice': '1'});
    await page.goto('/d/1');
    await expect(page.locator('.PostUser--anonymous').first()).toContainText(name);
    // The isolated site's core translations fall back to its English pack;
    // these checks exercise this extension's actual Chinese catalogues.
    await login(page, 'admin');
    await editFirstPost(page, '2');
    await expect(page.locator('.AnonymousIdentityModal')).toContainText(title);
    await page.locator('.AnonymousIdentityModal').getByRole('button', {name: edit, exact: true}).click();
    await expect(page.locator('.AnonymousEditIdentityToggle')).toHaveText(edit);
    await saveEdit(page, 3, 'Localized anonymous editor check ' + locale);
    await page.reload();
    await page.locator('.DiffDropdown .Dropdown-toggle').first().click();
    await expect(page.locator('.DiffDropdown .ParentDiff button').first()).toContainText(name);
    await page.locator('.DiffDropdown .ParentDiff button').first().click();
    await expect(page.locator('.DiffModal .Modal-header')).toContainText(name);
    await page.goto('/admin#/extension/adminlby-anonymous');
    await expect(page.getByText(settingLabel, {exact: true})).toBeVisible();
    await settings(request);
  });
}
