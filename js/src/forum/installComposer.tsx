import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import { extend, override } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import Model from 'flarum/common/Model';
import AnonymousAvatar from './components/AnonymousAvatar';
import IdentityModal from './components/IdentityModal';
import { chooseIdentity, loadWithIdentity, replyKind, Kind, Mode } from './identity';
import { originalAnonymousAuthor } from './editIdentity';

export async function chooseEditIdentity(post: any): Promise<boolean | undefined> {
  if (!app.forum.attribute('anonymousEditHistoryAvailable')) return undefined;
  if (originalAnonymousAuthor(post)) return true;
  if (!app.forum.attribute('anonymousEditHistoryChoice') || !post.attribute('canChooseEditHistoryIdentity')) return undefined;
  return chooseIdentity(app.forum.attribute('anonymousMode') as Mode, true, () => new Promise((resolve, reject) => {
    app.modal.show(IdentityModal, {resolve, reject, editing: true}).catch(reject);
  }));
}

export function allowed(kind: Kind, discussion?: any): boolean {
  if (!app.session.user || !app.forum.attribute('anonymousEnabled') || !app.forum.attribute(`anonymous${kind[0].toUpperCase()}${kind.slice(1)}`)) return false;
  return kind === 'discussions' ? !!app.forum.attribute('canAnonymousStartDiscussion')
    : !!app.forum.attribute('canAnonymousReply') && (!discussion || !!discussion.attribute('canAnonymousReply'));
}

export function choose(kind: Kind, discussion?: any): Promise<boolean> {
  return chooseIdentity(app.forum.attribute('anonymousMode') as Mode, allowed(kind, discussion), () => new Promise((resolve, reject) => {
    app.modal.show(IdentityModal, {resolve, reject}).catch(reject);
  }));
}

export default function installComposer() {
  // rc.8's sidebar resolves before its detached load() promise. Return the
  // whole operation so its existing click handler also handles cancellation.
  override('flarum/forum/components/IndexSidebar', 'newDiscussionAction', async function (original: any) {
    if (!app.session.user) return original();
    await app.composer.load(() => import('flarum/forum/components/DiscussionComposer'), {user: app.session.user});
    await app.composer.show();
    return app.composer;
  });
  // rc.8 loads composers asynchronously. Resolve the class but do not call
  // core load() or show() until the identity promise is fulfilled.
  override('flarum/forum/states/ComposerState', 'load', async function (original: any, component: any, attrs: any = {}) {
    let resolved = component;
    if (!(component.prototype instanceof Component)) resolved = (await component()).default;
    const DiscussionComposer = flarum.reg.checkModule('core', 'forum/components/DiscussionComposer');
    const ReplyComposer = flarum.reg.checkModule('core', 'forum/components/ReplyComposer');
    const EditComposer = flarum.reg.checkModule('core', 'forum/components/EditPostComposer');
    if (EditComposer && (resolved === EditComposer || resolved.prototype instanceof EditComposer) && !attrs.anonymousEditIdentitySelected) {
      const editHistoryAnonymous = await chooseEditIdentity(attrs.post);
      return original(resolved, {...attrs, editHistoryAnonymous, anonymousEditIdentitySelected: true});
    }
    const isDiscussion = DiscussionComposer && (resolved === DiscussionComposer || resolved.prototype instanceof DiscussionComposer);
    const isReply = ReplyComposer && (resolved === ReplyComposer || resolved.prototype instanceof ReplyComposer);
    if (!isDiscussion && !isReply || attrs.anonymousIdentitySelected) return original(resolved, attrs);
    const kind = isDiscussion ? 'discussions' : replyKind(attrs.originalContent || '');
    return loadWithIdentity(() => choose(kind, attrs.discussion), (next) => original(resolved, next), attrs);
  });

  for (const path of ['DiscussionComposer', 'ReplyComposer']) {
    extend(`flarum/forum/components/${path}`, 'data', function (data: any) {
      data.isAnonymous = !!this.attrs.isAnonymous;
    });
    extend(`flarum/forum/components/${path}`, 'headerItems', function (items: any) {
      const kind = path === 'DiscussionComposer' ? 'discussions' : replyKind(this.composer.fields.content());
      if (!allowed(kind, this.attrs.discussion) && !this.attrs.isAnonymous) return;
      items.add('anonymousIdentity', <Button type="button" className="Button Button--link AnonymousIdentityToggle"
        icon={this.attrs.isAnonymous ? 'fas fa-user-secret' : 'fas fa-user'} aria-pressed={this.attrs.isAnonymous ? 'true' : 'false'}
        onclick={() => {
          const anonymous = !this.attrs.isAnonymous && allowed(kind, this.attrs.discussion);
          this.composer.body.attrs.isAnonymous = anonymous;
          this.attrs.isAnonymous = anonymous;
        }}>
        {app.translator.trans(this.attrs.isAnonymous ? 'adminlby-anonymous.forum.post_anonymously' : 'adminlby-anonymous.forum.show_identity')}
      </Button>, 90);
    });
    override(`flarum/forum/components/${path}`, 'onsubmit', function (original: any, ...args: any[]) {
      const kind = path === 'DiscussionComposer' ? 'discussions' : replyKind(this.composer.fields.content());
      if (this.attrs.isAnonymous && !allowed(kind, this.attrs.discussion)) {
        app.alerts.show({type: 'error'}, app.translator.trans('adminlby-anonymous.forum.type_disabled'));
        return;
      }
      return original(...args);
    });
  }

  extend('flarum/forum/components/EditPostComposer', 'data', function (data: any) {
    if (this.attrs.editHistoryAnonymous !== undefined) data.editHistoryAnonymous = this.attrs.editHistoryAnonymous;
  });
  extend('flarum/forum/components/EditPostComposer', 'headerItems', function (items: any) {
    const locked = originalAnonymousAuthor(this.attrs.post);
    if (!app.forum.attribute('anonymousEditHistoryAvailable') || (!locked && !this.attrs.post.attribute('canChooseEditHistoryIdentity'))) return;
    const anonymous = locked || !!this.attrs.editHistoryAnonymous;
    items.add('anonymousEditIdentity', <Button type="button" className="Button Button--link AnonymousEditIdentityToggle" disabled={locked}
      icon={anonymous ? 'fas fa-user-secret' : 'fas fa-user'} aria-pressed={anonymous ? 'true' : 'false'}
      title={locked ? String(app.translator.trans('adminlby-anonymous.forum.edit_original_author_locked', {}, true)) : undefined}
      onclick={() => {
        this.composer.body.attrs.editHistoryAnonymous = !anonymous;
        this.attrs.editHistoryAnonymous = !anonymous;
      }}>
      {app.translator.trans(locked ? 'adminlby-anonymous.forum.edit_original_author_locked' : anonymous ? 'adminlby-anonymous.forum.edit_anonymously' : 'adminlby-anonymous.forum.show_identity')}
    </Button>, 90);
  });

  override('flarum/forum/components/ComposerBody', 'view', function (original: any) {
    const view = original();
    if (this.attrs.isAnonymous || this.attrs.editHistoryAnonymous || this.attrs.post?.attribute('isAnonymous')) {
      const replace = (node: any): void => {
        if (!node || typeof node !== 'object') return;
        if (node.attrs?.className === 'ComposerBody-avatar') {
          node.tag = AnonymousAvatar;
          node.attrs = {className: 'ComposerBody-avatar'};
        }
        if (Array.isArray(node.children)) node.children.forEach(replace);
      };
      replace(view);
    }
    return view;
  });

  // All user-initiated core Model.save creations get an identity choice, even
  // if another extension supplies its own composer for a custom Post type.
  override(Model.prototype, 'save', async function (original: any, attributes: any = {}, options: any = {}) {
    if (!this.exists && ['posts', 'discussions'].includes(this.data.type || '') && attributes.isAnonymous === undefined) {
      const kind = this.data.type === 'discussions' ? 'discussions'
        : attributes.contentType && attributes.contentType !== 'comment' ? 'other' : replyKind(attributes.content || '');
      attributes = {...attributes, isAnonymous: await choose(kind, attributes.relationships?.discussion)};
    }
    if (this.exists && this.data.type === 'posts' && attributes.content !== undefined && attributes.editHistoryAnonymous === undefined) {
      const editHistoryAnonymous = await chooseEditIdentity(this);
      if (editHistoryAnonymous !== undefined) attributes = {...attributes, editHistoryAnonymous};
    }
    return original(attributes, options);
  });
}

export async function quotedReply(post: any, quote?: string): Promise<void> {
  const discussion = post.discussion();
  if (!app.session.user) {
    await app.modal.show(() => import('flarum/forum/components/LogInModal'));
    return;
  }
  if (!discussion.canReply()) return;
  const editing = app.composer.bodyMatches('flarum/forum/components/EditPostComposer') && app.composer.body.attrs.post.discussion() === discussion;
  if (!editing) {
    const isAnonymous = await choose('quotes', discussion);
    if (!app.composer.composingReplyTo(discussion)) {
      await app.composer.load(() => import('flarum/forum/components/ReplyComposer'), {
        user: app.session.user, discussion, isAnonymous, anonymousIdentitySelected: true,
      });
      if (!app.composer.bodyMatches('flarum/forum/components/ReplyComposer', {discussion})) return; // A draft-discard prompt was cancelled.
    } else {
      app.composer.body.attrs.isAnonymous = isAnonymous;
    }
    await app.composer.show();
  }
  await app.composer.editorReady();
  const anonymous = post.attribute('isAnonymous');
  const name = anonymous ? app.translator.trans('adminlby-anonymous.forum.anonymous_user', {}, true) : post.user()?.displayName() || app.translator.trans('core.lib.username.deleted_text', {}, true);
  const safeName = String(name).replace(/"#[a-z]*\d+/g, '_');
  const mention = `@"${safeName}"#p${post.id()} `;
  if (!app.composer.fields.content()) app.composer.body.attrs.originalContent = mention;
  const cursor = app.composer.editor!.getSelectionRange()[0];
  const preceding = app.composer.fields.content().slice(0, cursor);
  const gap = preceding ? '\n'.repeat(Math.max(0, 2 - (preceding.match(/\n*$/)?.[0].length || 0))) : '';
  app.composer.editor!.insertAtCursor(gap + (quote ? `> ${mention}${quote.trim().replace(/\n/g, '\n> ')}\n\n` : mention), false);
}
