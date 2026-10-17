import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import type StatusState from '../utils/StatusState';
import { t, ts } from '../utils/trans';
import { missingFor, type MailReplyStatus } from '../types';

export interface StatusLineAttrs extends ComponentAttrs {
  state: StatusState;
}

type Tone = 'ok' | 'warn' | 'off';

const STALE_FETCH_SECONDS = 600;

/**
 * Linha compacta de saúde do modo salvo: recebendo, precisando de atenção ou
 * desligado, com o que ainda falta configurar.
 */
export default class StatusLine extends Component<StatusLineAttrs> {
  view() {
    const { state } = this.attrs;
    const data = state.data;

    if (!data) {
      return (
        <div className="MailReplyAdmin-status" data-tone="off" role={state.error ? 'alert' : undefined}>
          {state.error ? (
            <>
              <span className="MailReplyAdmin-status-main">{t('status_error')}</span>
              <Button className="Button Button--text" icon="fas fa-redo" onclick={() => state.load()}>
                {t('status_retry')}
              </Button>
            </>
          ) : (
            <LoadingIndicator size="small" display="inline" />
          )}
        </div>
      );
    }

    const missing = missingFor(data);
    const [tone, headline] = this.health(data, missing.length);

    return (
      <div className="MailReplyAdmin-status" data-tone={tone}>
        <span className="MailReplyAdmin-status-dot" aria-hidden="true" />
        <div className="MailReplyAdmin-status-main">
          <strong>{t(headline)}</strong>
          <span className="MailReplyAdmin-status-detail">{this.detail(data)}</span>
          {missing.length ? (
            <ul className="MailReplyAdmin-status-missing">
              <li className="MailReplyAdmin-status-missingLabel">{t('status_missing')}</li>
              {missing.map((key) => (
                <li key={key}>{t('check_' + key)}</li>
              ))}
            </ul>
          ) : null}
        </div>
        <Button
          className="Button Button--icon Button--flat"
          icon={'fas fa-sync-alt' + (state.loading ? ' fa-spin' : '')}
          aria-label={ts('status_refresh')}
          title={ts('status_refresh')}
          disabled={state.loading}
          onclick={() => state.load()}
        />
      </div>
    );
  }

  /**
   * Classifica a saúde do modo salvo: desligado, setup incompleto, poller
   * parado ou sem coleta recente, ou recebendo normalmente.
   */
  health(data: MailReplyStatus, missing: number): [Tone, string] {
    if (data.mode === 'disabled') return ['off', 'health_off'];
    if (missing > 0) return ['warn', 'health_setup'];

    if (data.mode === 'imap') {
      const last = data.polling.last_fetch;
      const stale = !last || Date.now() / 1000 - last.at > STALE_FETCH_SECONDS;
      const loopDown = data.polling.queue === 'async' && !data.polling.loop_alive;

      if (loopDown || stale) return ['warn', 'health_poller'];
    }

    return ['ok', 'health_ok'];
  }

  detail(data: MailReplyStatus) {
    if (data.mode === 'disabled') return t('polling_disabled');

    if (data.mode === 'webhook') {
      return t('polling_webhook', { url: <code>{app.forum.attribute<string>('apiUrl') + '/mail-reply/inbound'}</code> });
    }

    const { queue, loop_alive, last_fetch } = data.polling;
    const how =
      queue === 'sync' ? t('polling_scheduler', { code: <code /> }) : loop_alive ? t('polling_loop') : t('polling_loop_down', { code: <code /> });

    return [
      how,
      ' ',
      last_fetch
        ? t('last_fetch', {
            ago: humanTime(new Date(last_fetch.at * 1000)),
            processed: last_fetch.processed,
            failed: last_fetch.failed,
            skipped: last_fetch.skipped,
          })
        : t('last_fetch_never'),
    ];
  }
}
