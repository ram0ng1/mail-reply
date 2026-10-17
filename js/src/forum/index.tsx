import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';

export { default as extend } from './extend';

app.initializers.add('ramon-mail-reply', () => {
  extend(CommentPost.prototype, 'headerItems', function (items) {
    if (!app.forum.attribute<boolean>('mailReply.showBadge')) return;
    if (!this.attrs.post.viaMailReply()) return;

    const label = app.translator.trans('ramon-mail-reply.forum.via_mail', {}, true);

    items.add(
      'mailReplyBadge',
      <span className="MailReplyBadge" title={label} aria-label={label}>
        <i className="icon fas fa-envelope" aria-hidden="true" />
        <span className="MailReplyBadge-text">{label}</span>
      </span>,
      95
    );
  });
});
