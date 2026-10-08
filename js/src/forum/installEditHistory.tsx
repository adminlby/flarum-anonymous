import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import Icon from 'flarum/common/components/Icon';
import Button from 'flarum/common/components/Button';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import AnonymousAvatar from './components/AnonymousAvatar';
import RevealAuthorModal from './components/RevealAuthorModal';

export default function installEditHistory() {
  // rc.8 retains a post's children while its model and author stay unchanged.
  // Anonymous posts have no author relationship to invalidate that cache.
  const updates = new Map<string, number>();
  extend('flarum/forum/components/CommentPost', 'oninit', function () {
    this.subtree.check(() => updates.get(this.attrs.post.id()) || 0);
  });
  flarum.reg.onLoad('huseyinfiliz-diff', 'forum/states/DiffListState', (State: any) => {
    extend(State.prototype, 'redrawList', function () {
      updates.set(this.post.id(), (updates.get(this.post.id()) || 0) + 1);
    });
  });
  flarum.reg.onLoad('huseyinfiliz-diff', 'forum/components/DiffButton', (DiffButton: any) => {
    override(DiffButton.prototype, 'getButtonContent', function (original: any) {
      const revision = this.attrs.item;
      const deletion = revision.deletedAt() && this.attrs.subButton === true;
      if (!revision.attribute(deletion ? 'deletedByAnonymous' : 'isAnonymous')) return original();
      const created = revision.revision() === 0;
      const key = deletion ? 'adminlby-anonymous.forum.history_deleted' : created ? 'adminlby-anonymous.forum.history_created' : 'adminlby-anonymous.forum.history_edited';
      let label = extractText(app.translator.trans(key, {
        ago: humanTime(deletion ? revision.deletedAt() : created ? this.attrs.postDate : revision.createdAt()),
      }));
      if (revision.deletedAt() && !deletion) label += ' ' + extractText(app.translator.trans('adminlby-anonymous.forum.history_deleted_suffix'));
      return [<AnonymousAvatar />,
        revision.deletedAt() && !deletion ? <Icon name="fas fa-caret-down" className="Button-caret" /> : null,
        <span className="Button-label" title={label}>{deletion ? <em>{label}</em> : label}</span>];
    });
  });
  flarum.reg.onLoad('huseyinfiliz-diff', 'forum/components/DiffModal', (DiffModal: any) => {
    override(DiffModal.prototype, 'title', function (original: any) {
      const revision = this.attrs.listState.selectedItem;
      if (!revision.attribute('isAnonymous')) return original();
      const created = revision.revision() === 0;
      return [<AnonymousAvatar />,
        app.translator.trans(created ? 'adminlby-anonymous.forum.history_created' : 'adminlby-anonymous.forum.history_edited', {
          ago: humanTime(created ? this.attrs.listState.post.createdAt() : revision.createdAt()),
        }),
        revision.attribute('isMine') && app.forum.attribute('anonymousShowOwn')
          ? <span className="AnonymousOwnPost">{app.translator.trans('adminlby-anonymous.forum.own_edit')}</span> : null,
        revision.attribute('canRevealAnonymousEditor') ? <Button className="Button Button--link AnonymousRevealEditor" icon="fas fa-user-shield"
          onclick={() => app.modal.show(RevealAuthorModal, {revision})}>
          {app.translator.trans('adminlby-anonymous.forum.reveal_editor_button')}
        </Button> : null];
    });
  });
}
