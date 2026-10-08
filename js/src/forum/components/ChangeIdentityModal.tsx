import app from 'flarum/forum/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Post from 'flarum/common/models/Post';

interface Attrs extends IInternalModalAttrs { post: Post; }

export default class ChangeIdentityModal extends Modal<Attrs> {
  className() { return 'AnonymousChangeIdentityModal Modal--small'; }
  title() {
    return app.translator.trans(this.attrs.post.attribute('isAnonymous')
      ? 'adminlby-anonymous.forum.convert_named_title' : 'adminlby-anonymous.forum.convert_anonymous_title');
  }

  async changeIdentity() {
    this.loading = true;
    try {
      await app.request({method: 'POST', url: `${app.forum.attribute('apiUrl')}/anonymous/posts/${this.attrs.post.id()}/identity`,
        body: {data: {attributes: {isAnonymous: !this.attrs.post.attribute('isAnonymous')}}}});
      // Drop stale user/history/notification relationships together. A normal
      // resource request after reload receives the new server privacy state.
      window.location.reload();
    } catch {
      this.loading = false;
      m.redraw();
    }
  }

  content() {
    if (this.loading) return <div className="Modal-body"><LoadingIndicator /></div>;
    const anonymous = !this.attrs.post.attribute('isAnonymous');
    return <div className="Modal-body">
      <p>{app.translator.trans(anonymous ? 'adminlby-anonymous.forum.convert_anonymous_help' : 'adminlby-anonymous.forum.convert_named_help')}</p>
      <p>{app.translator.trans('adminlby-anonymous.forum.convert_related_help')}</p>
      <Button type="button" className="Button Button--primary" icon={anonymous ? 'fas fa-user-secret' : 'fas fa-user'} onclick={() => this.changeIdentity()}>
        {app.translator.trans(anonymous ? 'adminlby-anonymous.forum.convert_anonymous_confirm' : 'adminlby-anonymous.forum.convert_named_confirm')}
      </Button>
    </div>;
  }
}
