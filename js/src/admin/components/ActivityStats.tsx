import Component, { type ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type StatusState from '../utils/StatusState';
import { t } from '../utils/trans';
import { INBOUND_STATUSES } from '../types';

export interface ActivityStatsAttrs extends ComponentAttrs {
  state: StatusState;
}

export default class ActivityStats extends Component<ActivityStatsAttrs> {
  view() {
    const data = this.attrs.state.data;

    if (!data) {
      return this.attrs.state.error ? <p className="MailReplyAdmin-hint">{t('status_error')}</p> : <LoadingIndicator size="small" display="inline" />;
    }

    return (
      <div className="MailReplyAdmin-stats">
        {INBOUND_STATUSES.map((status) => (
          <div key={status} className="MailReplyAdmin-stat" data-status={status}>
            <span className="MailReplyAdmin-stat-label">{t('status_' + status)}</span>
            <span className="MailReplyAdmin-stat-value">{data.stats.day[status]}</span>
            <span className="MailReplyAdmin-stat-sub">
              {t('stat_day')} · {t('stat_week', { count: data.stats.week[status] })}
            </span>
          </div>
        ))}
      </div>
    );
  }
}
