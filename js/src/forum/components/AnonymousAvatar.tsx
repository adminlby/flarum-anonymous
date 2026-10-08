import Component, { ComponentAttrs } from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import app from 'flarum/forum/app';

interface Attrs extends ComponentAttrs { className?: string; }

export default class AnonymousAvatar extends Component<Attrs> {
  view() {
    return <span className={`Avatar AnonymousAvatar ${this.attrs.className || ''}`} title={app.translator.trans('adminlby-anonymous.forum.anonymous_user', {}, true)}>
      <Icon name="fas fa-user-secret" />
    </span>;
  }
}
