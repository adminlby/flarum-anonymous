import app from 'flarum/forum/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Post from 'flarum/common/models/Post';
import type Mithril from 'mithril';

interface Author { userId: number | null; displayName: string | null; username: string | null; deleted: boolean; }
interface Attrs extends IInternalModalAttrs { post?: Post; revision?: any; }

export default class RevealAuthorModal extends Modal<Attrs> {
  author: Author | null = null;
  className() { return 'AnonymousRevealModal Modal--small'; }
  title() { return app.translator.trans(this.attrs.revision ? 'adminlby-anonymous.forum.reveal_editor_title' : 'adminlby-anonymous.forum.reveal_title'); }

  async reveal() {
    this.loading = true;
    try {
      const result = await app.request<{data: {attributes: Author}}>({
        method: 'POST', url: `${app.forum.attribute('apiUrl')}/anonymous/${this.attrs.revision ? `diff/${this.attrs.revision.id()}` : `posts/${this.attrs.post!.id()}`}/reveal`,
      });
      // Never push a reveal response into Flarum's model store or post.user().
      this.author = result.data.attributes;
    } catch {
      // Flarum's request handler displays the localized API error.
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  content() {
    if (this.loading) return <div className="Modal-body"><LoadingIndicator /></div>;
    return <div className="Modal-body">
      {this.author ? <dl>
        <dt>{app.translator.trans('adminlby-anonymous.forum.author_id')}</dt><dd>{this.author.userId ?? app.translator.trans('adminlby-anonymous.forum.deleted_author')}</dd>
        <dt>{app.translator.trans('adminlby-anonymous.forum.author_name')}</dt><dd>{this.author.displayName ?? app.translator.trans('adminlby-anonymous.forum.deleted_author')}</dd>
        <dt>{app.translator.trans('adminlby-anonymous.forum.author_username')}</dt><dd>{this.author.username ?? app.translator.trans('adminlby-anonymous.forum.deleted_author')}</dd>
      </dl> : <>
        <p>{app.translator.trans(this.attrs.revision ? 'adminlby-anonymous.forum.reveal_editor_help' : 'adminlby-anonymous.forum.reveal_help')}</p>
        <Button type="button" className="Button Button--primary" onclick={() => this.reveal()}>
          {app.translator.trans(this.attrs.revision ? 'adminlby-anonymous.forum.reveal_editor_button' : 'adminlby-anonymous.forum.reveal_button')}
        </Button>
      </>}
    </div>;
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    this.author = null;
    super.onremove(vnode);
  }
}
