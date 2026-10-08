import app from 'flarum/forum/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

interface Attrs extends IInternalModalAttrs {
  resolve: (anonymous: boolean) => void;
  reject: (reason: unknown) => void;
  editing?: boolean;
}

export default class IdentityModal extends Modal<Attrs> {
  settled = false;

  className() { return 'AnonymousIdentityModal Modal--small'; }
  title() { return app.translator.trans(this.attrs.editing ? 'adminlby-anonymous.forum.edit_identity_title' : 'adminlby-anonymous.forum.identity_title'); }

  content() {
    return <div className="Modal-body">
      <p>{app.translator.trans(this.attrs.editing ? 'adminlby-anonymous.forum.edit_identity_help' : 'adminlby-anonymous.forum.identity_help')}</p>
      <div className="AnonymousIdentityModal-options">
        <Button type="button" className="Button Button--primary" icon="fas fa-user-secret" onclick={() => this.choose(true)}>
          {app.translator.trans(this.attrs.editing ? 'adminlby-anonymous.forum.edit_anonymously' : 'adminlby-anonymous.forum.post_anonymously')}
        </Button>
        <Button type="button" className="Button" icon="fas fa-user" onclick={() => this.choose(false)}>
          {app.translator.trans('adminlby-anonymous.forum.show_identity')}
        </Button>
      </div>
    </div>;
  }

  choose(anonymous: boolean) {
    this.settled = true;
    this.attrs.resolve(anonymous);
    this.hide();
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    if (!this.settled) this.attrs.reject(new Error('anonymous-identity-cancelled'));
  }
}
