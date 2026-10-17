import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import type StatusState from '../utils/StatusState';
import { hasKey, t } from '../utils/trans';
import { INBOUND_STATUSES, type InboundStatus, type RecentEntry } from '../types';

export interface ActivityFeedAttrs extends ComponentAttrs {
  state: StatusState;
}

type Filter = InboundStatus | 'all';

export default class ActivityFeed extends Component<ActivityFeedAttrs> {
  filter: Filter = 'all';

  view() {
    const { state } = this.attrs;

    if (!state.data) {
      return state.error ? (
        <div className="MailReplyAdmin-feed-empty" role="alert">
          <Icon name="fas fa-exclamation-triangle" />
          <span>{t('status_error')}</span>
          <Button className="Button" icon="fas fa-redo" onclick={() => state.load()}>
            {t('status_retry')}
          </Button>
        </div>
      ) : (
        <LoadingIndicator size="medium" />
      );
    }

    const recent = state.data.recent;
    const shown = this.filter === 'all' ? recent : recent.filter((entry) => entry.status === this.filter);

    return (
      <div className="MailReplyAdmin-feed">
        <div className="MailReplyAdmin-feed-toolbar">
          {recent.length ? (
            <div className="MailReplyAdmin-feed-filters" role="group">
              {(['all', ...INBOUND_STATUSES] as Filter[]).map((filter) => (
                <Button
                  className={'Button Button--flat MailReplyAdmin-feed-filter' + (filter === this.filter ? ' is-active' : '')}
                  aria-pressed={filter === this.filter ? 'true' : 'false'}
                  data-status={filter}
                  onclick={() => (this.filter = filter)}
                >
                  {t(filter === 'all' ? 'feed_filter_all' : 'status_' + filter)}
                  <span className="MailReplyAdmin-feed-count">
                    {filter === 'all' ? recent.length : recent.filter((entry) => entry.status === filter).length}
                  </span>
                </Button>
              ))}
            </div>
          ) : null}
          <Button
            className="Button Button--flat MailReplyAdmin-feed-refresh"
            icon={'fas fa-sync-alt' + (state.loading ? ' fa-spin' : '')}
            disabled={state.loading}
            onclick={() => state.load()}
          >
            {t('status_refresh')}
          </Button>
        </div>

        {shown.length ? (
          <ol className="MailReplyAdmin-feed-list">{shown.map((entry) => this.item(entry))}</ol>
        ) : (
          <div className="MailReplyAdmin-feed-empty">
            <Icon name="far fa-envelope-open" />
            <span>{t(recent.length ? 'feed_empty_filter' : 'feed_empty')}</span>
          </div>
        )}
      </div>
    );
  }

  item(entry: RecentEntry) {
    return (
      <li key={entry.id} className="MailReplyAdmin-feed-item" data-status={entry.status}>
        <span className="MailReplyAdmin-pill" data-status={entry.status}>
          {t('status_' + entry.status)}
        </span>
        <div className="MailReplyAdmin-feed-main">
          <div className="MailReplyAdmin-feed-line">
            <span className="MailReplyAdmin-feed-user">
              <Icon name="fas fa-user" />
              {entry.user ? entry.user.username : t('feed_unknown_user')}
            </span>
            <Icon name="fas fa-long-arrow-alt-right" className="MailReplyAdmin-feed-arrow" />
            {this.discussionLink(entry)}
          </div>
          {entry.reason ? <div className="MailReplyAdmin-feed-reason">{this.reason(entry.reason)}</div> : null}
        </div>
        <span className="MailReplyAdmin-feed-time">{humanTime(new Date(entry.createdAt))}</span>
      </li>
    );
  }

  discussionLink(entry: RecentEntry) {
    const discussion = entry.discussion;
    if (!discussion) return <span className="MailReplyAdmin-feed-missing">{t('feed_no_discussion')}</span>;

    const href =
      app.forum.attribute<string>('baseUrl') +
      '/d/' +
      discussion.id +
      '-' +
      encodeURIComponent(discussion.slug) +
      (entry.postNumber ? '/' + entry.postNumber : '');

    return (
      <a className="MailReplyAdmin-feed-discussion" href={href} target="_blank" rel="noopener noreferrer">
        {discussion.title}
        {entry.postNumber ? <span className="MailReplyAdmin-feed-post">#{entry.postNumber}</span> : null}
      </a>
    );
  }

  reason(code: string) {
    return hasKey('reason_' + code) ? t('reason_' + code) : <code>{code}</code>;
  }
}
