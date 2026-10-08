import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import humanTime from 'flarum/common/helpers/humanTime';
import HeaderListItem from 'flarum/forum/components/HeaderListItem';
import Icon from 'flarum/common/components/Icon';
import Tooltip from 'flarum/common/components/Tooltip';
import username from 'flarum/common/helpers/username';
import punctuateSeries from 'flarum/common/helpers/punctuateSeries';
import humanTimeText from 'flarum/common/utils/humanTime';
import AnonymousAvatar from './components/AnonymousAvatar';
import RevealAuthorModal from './components/RevealAuthorModal';
import ChangeIdentityModal from './components/ChangeIdentityModal';
import installComposer, {quotedReply} from './installComposer';
import installEditHistory from './installEditHistory';
import installCompatibility from './installCompatibility';

export default app.initializers.add('adminlby-anonymous', () => {
  installComposer();
  installEditHistory();
  installCompatibility();

  override('flarum/forum/components/PostUser', 'view', function (original: any) {
    const post = this.attrs.post;
    if (!post.attribute('isAnonymous')) return original();
    return <div className="PostUser PostUser--anonymous"><h3 className="PostUser-name">
      <AnonymousAvatar className="Post-avatar" /><span className="username">{app.translator.trans('adminlby-anonymous.forum.anonymous_user')}</span>
    </h3>{post.attribute('isMine') && app.forum.attribute('anonymousShowOwn') && <span className="AnonymousOwnPost">{app.translator.trans('adminlby-anonymous.forum.own_post')}</span>}</div>;
  });

  flarum.reg.onLoad('core', 'forum/utils/PostControls', (controls: any) => {
    extend(controls, 'moderationControls', function (items: any, post: any) {
      if (post.attribute('canChangeAnonymousIdentity')) {
        items.add('anonymousChangeIdentity', <Button icon="fas fa-user-secret" onclick={() => app.modal.show(ChangeIdentityModal, {post})}>
          {app.translator.trans(post.attribute('isAnonymous') ? 'adminlby-anonymous.forum.convert_named_button' : 'adminlby-anonymous.forum.convert_anonymous_button')}
        </Button>);
      }
      if (post.attribute('isAnonymous') && post.attribute('canRevealAnonymousAuthor')) {
        items.add('anonymousReveal', <Button icon="fas fa-user-shield" onclick={() => app.modal.show(RevealAuthorModal, {post})}>
          {app.translator.trans('adminlby-anonymous.forum.reveal_button')}
        </Button>);
      }
    });
  });

  override('flarum/forum/components/PostEdited', 'view', function (original: any) {
    const post = this.attrs.post;
    if (!post.attribute('isAnonymous') && !post.attribute('lastEditIsAnonymous')) return original();
    return <Tooltip text={app.translator.trans('adminlby-anonymous.forum.edited_tooltip', {ago: humanTimeText(post.editedAt())})}>
      <span className="PostEdited">{app.translator.trans('adminlby-anonymous.forum.edited_anonymously')}</span>
    </Tooltip>;
  });

  override('flarum/forum/components/EventPost', 'avatar', function (original: any) {
    return this.attrs.post.attribute('isAnonymous') ? <AnonymousAvatar className="EventPost-icon" /> : original();
  });

  override('flarum/forum/components/EventPost', 'content', function (original: any) {
    const post = this.attrs.post;
    if (!post.attribute('isAnonymous')) return original();
    // Core subclasses override description(), so changing only the base
    // description would leave renamed posts displaying "Deleted user".
    const Post = flarum.reg.checkModule('core', 'forum/components/Post');
    const data = {...this.descriptionData(), user: undefined,
      username: <span className="username">{app.translator.trans('adminlby-anonymous.forum.anonymous_user')}</span>,
      time: humanTime(post.createdAt()),
    };
    return Post.prototype.content.call(this).concat([<div className="EventPost-info">{this.description(data)}
      {post.attribute('isMine') && app.forum.attribute('anonymousShowOwn') && <span className="AnonymousOwnPost">{app.translator.trans('adminlby-anonymous.forum.own_post')}</span>}
    </div>]);
  });

  override('flarum/forum/components/PostPreview', 'view', function (original: any) {
    const post = this.attrs.post;
    if (!post.attribute('isAnonymous')) return original();
    return <Link className="PostPreview" href={app.route.post(post)} onclick={this.attrs.onclick}><span className="PostPreview-content">
      <AnonymousAvatar />{app.translator.trans('adminlby-anonymous.forum.anonymous_user')} <span className="PostPreview-excerpt">{this.excerpt()}</span>
    </span></Link>;
  });

  override('flarum/forum/components/DiscussionListItem', 'authorItems', function (original: any) {
    const items = original();
    if (this.attrs.discussion.attribute('isAnonymous')) {
      items.setContent('avatar', <span className="DiscussionListItem-author-avatar"><AnonymousAvatar /></span>);
    }
    return items;
  });

  override('flarum/forum/components/TerminalPost', 'view', function (original: any) {
    const discussion = this.attrs.discussion;
    const last = !!this.attrs.lastPost && !!discussion.replyCount();
    if (!discussion.attribute(last ? 'lastPostIsAnonymous' : 'isAnonymous')) return original();
    return <span className="AnonymousTerminalPost">{app.translator.trans(last ? 'adminlby-anonymous.forum.replied' : 'adminlby-anonymous.forum.started', {ago: humanTime(discussion[last ? 'lastPostedAt' : 'createdAt']())})}</span>;
  });

  override('flarum/forum/components/Notification', 'view', function (original: any) {
    const notification = this.attrs.notification;
    if (!notification.attribute('isAnonymous')) return original();
    const known = ['newPost', 'postMentioned', 'userMentioned', 'groupMentioned', 'discussionRenamed'];
    const type = known.includes(notification.contentType()) ? notification.contentType() : 'generic';
    return <HeaderListItem className={`Notification Notification--${notification.contentType()} ${notification.isRead() ? '' : 'unread'}`}
      avatar={<AnonymousAvatar />} icon={this.icon()}
      content={app.translator.trans(`adminlby-anonymous.forum.notifications.${type}`)}
      excerpt={this.excerpt()} datetime={notification.createdAt()} href={this.href() || ''}
      onclick={this.markAsRead.bind(this)} actions={this.actionItems().toArray()} />;
  });

  // Mentions uses its own name formatter. Never synthesize a User model.
  override('ext:flarum/mentions/forum/mentionables/PostMention', 'replacement', function (original: any, post: any) {
    return post.attribute('isAnonymous') ? `@"${app.translator.trans('adminlby-anonymous.forum.anonymous_user', {}, true)}"#p${post.id()}` : original(post);
  });
  override('ext:flarum/mentions/forum/mentionables/PostMention', 'suggestion', function (original: any, post: any, typed: any) {
    return post.attribute('isAnonymous') ? <><AnonymousAvatar />{app.translator.trans('adminlby-anonymous.forum.anonymous_user')} {app.translator.trans('flarum-mentions.forum.composer.reply_to_post_text', {number: post.number()})}</> : original(post, typed);
  });

  // Mentions inserts quoted text only after opening the core reply composer.
  // Select the quote identity first, so its independent switch also controls
  // the pre-composer dialog rather than the ordinary-reply switch.
  const reply = (post: any, quote?: string) => quotedReply(post, quote).catch((error) => {
    if (error?.message !== 'anonymous-identity-cancelled') throw error;
  });
  extend('flarum/forum/components/CommentPost', 'actionItems', function (items: any) {
    if ((app as any).mentionFormats && items.has('reply')) items.get('reply').attrs.onclick = () => reply(this.attrs.post);
  });
  override('ext:flarum/mentions/forum/fragments/PostQuoteButton', 'view', function (original: any) {
    const view = original();
    view.attrs.onclick = () => reply(this.post, this.content);
    return view;
  });

  extend('flarum/forum/components/CommentPost', 'footerItems', function (items: any) {
    const post = this.attrs.post;
    const replies = post.mentionedBy?.();
    if (!replies?.some((reply: any) => reply.attribute('isAnonymous')) || !items.has('replies')) return;
    const names = replies.slice(0, 4).map((reply: any) => <Link href={app.route.post(reply)} data-number={reply.number()}>
      {reply.attribute('isAnonymous') ? app.translator.trans('adminlby-anonymous.forum.anonymous_user') : username(reply.user())}
    </Link>);
    items.setContent('replies', <div className="Post-mentionedBy"><span className="Post-mentionedBy-summary"><Icon name="fas fa-reply" />
      {app.translator.trans('flarum-mentions.forum.post.mentioned_by_text', {count: names.length, users: punctuateSeries(names)})}
    </span></div>);
  });
}, -50);
