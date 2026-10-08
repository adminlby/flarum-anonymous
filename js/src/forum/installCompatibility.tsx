import app from 'flarum/forum/app';
import { override } from 'flarum/common/extend';
import AnonymousAvatar from './components/AnonymousAvatar';

export default function installCompatibility() {
  flarum.reg.onLoad('fof-polls', 'forum/components/ListVotersModal', (Modal: any) => {
    override(Modal.prototype, 'voteContent', function (original: any, vote: any) {
      return vote.attribute('isAnonymous') ? <span className="AnonymousPollVote"><AnonymousAvatar />{' '}
        {app.translator.trans('adminlby-anonymous.forum.anonymous_user')}</span> : original(vote);
    });
  });
}
