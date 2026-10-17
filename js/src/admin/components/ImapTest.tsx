import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import { getStr } from '../utils/settings';
import { t, ts } from '../utils/trans';

interface ImapTestPayload {
  imap_host: string;
  imap_port: string;
  imap_encryption: string;
  imap_username: string;
  imap_password: string;
  imap_mailbox: string;
  imap_validate_cert: string;
}

interface ImapTestResponse {
  ok: boolean;
  code?: string;
  field?: string;
  message?: string;
  mailbox?: string;
  unseen?: number;
  expected_capture_pattern?: string | null;
}

interface TestResult {
  ok: boolean;
  text: string;
}

const FIELDS = ['imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password', 'imap_mailbox', 'imap_validate_cert'] as const;

const FIELD_LABELS: Record<string, string> = {
  host: 'imap_host_label',
  username: 'imap_username_label',
  password: 'imap_password_label',
};

/**
 * Testa a conexão com os valores atuais. Só aparece com host, usuário e
 * senha preenchidos; antes disso a dica diz o que falta.
 */
export default class ImapTest extends Component {
  loading = false;
  result: TestResult | null = null;

  view() {
    if (!getStr('imap_host').trim() || !getStr('imap_username').trim() || !getStr('imap_password')) {
      return <p className="MailReplyAdmin-hint">{t('imap_test_needs_fields')}</p>;
    }

    return (
      <div className="Form-group">
        <p className="helpText">{t('imap_test_help')}</p>
        <Button className="Button" icon="fas fa-plug" loading={this.loading} onclick={() => this.run()}>
          {this.loading ? t('imap_test_running') : t('imap_test_button')}
        </Button>
        {this.result ? (
          <div className="MailReplyAdmin-testResult" data-ok={this.result.ok ? '1' : '0'} role="status">
            {this.result.text}
          </div>
        ) : null}
      </div>
    );
  }

  run() {
    if (this.loading) return;

    const payload = Object.fromEntries(FIELDS.map((name) => [name, getStr(name)])) as unknown as ImapTestPayload;
    this.loading = true;
    this.result = null;

    app
      .request<ImapTestResponse>({
        method: 'POST',
        url: app.forum.attribute<string>('apiUrl') + '/mail-reply/test-imap',
        body: payload,
        errorHandler: () => {},
      })
      .then(
        (res) => this.finish(this.describe(res, payload)),
        () => this.finish({ ok: false, text: ts('imap_test_error_request') })
      );
  }

  finish(result: TestResult) {
    this.loading = false;
    this.result = result;
    app.alerts.show({ type: result.ok ? 'success' : 'error' }, result.text);
    m.redraw();
  }

  describe(res: ImapTestResponse, payload: ImapTestPayload): TestResult {
    if (res?.ok) {
      return {
        ok: true,
        text: ts('imap_test_ok', {
          mailbox: res.mailbox ?? payload.imap_mailbox,
          unseen: res.unseen ?? 0,
          pattern: res.expected_capture_pattern || '—',
        }),
      };
    }

    switch (res?.code) {
      case 'missing_dependency':
        return { ok: false, text: ts('imap_test_error_missing_dependency') };
      case 'missing_field': {
        const labelKey = FIELD_LABELS[res.field ?? ''];
        return { ok: false, text: ts('imap_test_error_missing_field', { field: labelKey ? ts(labelKey) : (res.field ?? '') }) };
      }
      case 'mailbox_not_found':
        return { ok: false, text: ts('imap_test_error_mailbox_not_found', { mailbox: payload.imap_mailbox || 'INBOX' }) };
      case 'connection_failed':
        return { ok: false, text: ts('imap_test_error_connection_failed', { message: res.message ?? '' }) };
      default:
        return { ok: false, text: ts('imap_test_error_request') };
    }
  }
}
