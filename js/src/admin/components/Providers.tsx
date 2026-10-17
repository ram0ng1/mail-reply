import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import AdminText from './AdminText';
import { SubDivider } from './AdminCard';
import copyToClipboard from '../utils/copyToClipboard';
import { escapeRegex, parseReplyAddress } from '../utils/replyAddress';
import { getStr } from '../utils/settings';
import { t, ts } from '../utils/trans';

export interface ProvidersAttrs extends ComponentAttrs {
  inboundUrl: string;
}

/**
 * URL e header para colar no provedor, a signing key do Mailgun e o passo a
 * passo de cada provedor.
 */
export default class Providers extends Component<ProvidersAttrs> {
  view() {
    const { inboundUrl } = this.attrs;
    const secret = getStr('webhook_secret');
    const header = `X-Mail-Reply-Token: ${secret || ts('secret_placeholder')}`;

    return (
      <>
        <p className="helpText MailReplyAdmin-intro">{t('providers_help')}</p>
        <div className="MailReplyAdmin-kv">
          <div className="MailReplyAdmin-kv-row">
            <strong>{t('providers_url_label')}</strong>
            <code>{inboundUrl}</code>
            {this.copyButton(inboundUrl, 'url_copied')}
          </div>
          <div className="MailReplyAdmin-kv-row">
            <strong>{t('providers_header_label')}</strong>
            <code>{header}</code>
            {secret ? this.copyButton(header, 'header_copied') : null}
          </div>
        </div>

        <details className="MailReplyAdmin-details">
          <summary>{t('providers_mailgun_summary')}</summary>
          <ol>
            <li>{t('providers_mailgun_step1')}</li>
            <li>
              {t('providers_mailgun_step2')} <code>{this.recipientPattern()}</code>
            </li>
            <li>
              {t('providers_mailgun_step3')} <code>{`forward("${inboundUrl}")`}</code>
            </li>
            <li>{t('providers_mailgun_step4')}</li>
          </ol>
        </details>

        <details className="MailReplyAdmin-details">
          <summary>{t('providers_postmark_summary')}</summary>
          <ol>
            <li>{t('providers_postmark_step1')}</li>
            <li>
              {t('providers_postmark_step2')} <code>{inboundUrl}</code>
            </li>
            <li>
              {t('providers_postmark_step3')} <code>{header}</code>
            </li>
            <li>{t('providers_postmark_step4')}</li>
          </ol>
        </details>

        <details className="MailReplyAdmin-details">
          <summary>{t('providers_cloudflare_summary')}</summary>
          <ol>
            <li>{t('providers_cf_step1')}</li>
            <li>
              {t('providers_cf_step2')} <code>{inboundUrl}</code>
            </li>
            <li>
              {t('providers_cf_step3')} <code>{header}</code>
            </li>
            <li>{t('providers_cf_step4')}</li>
          </ol>
        </details>

        <SubDivider />

        <AdminText setting="mailgun_signing_key" type="password" label={t('mailgun_signing_key_label')} help={t('mailgun_signing_key_help')} />
      </>
    );
  }

  copyButton(text: string, successKey: string) {
    return (
      <Button
        className="Button Button--icon Button--flat"
        icon="fas fa-copy"
        aria-label={ts('copy_button')}
        title={ts('copy_button')}
        onclick={() => copyToClipboard(text, successKey)}
      />
    );
  }

  /**
   * Regex de destinatário para a rota do Mailgun, derivada da caixa de
   * resposta configurada; cai num exemplo genérico enquanto ela está vazia.
   */
  recipientPattern(): string {
    const parts = parseReplyAddress(getStr('reply_address')) ?? { local: 'reply', domain: 'example.com' };

    return `^${escapeRegex(parts.local)}\\+.*@${escapeRegex(parts.domain)}$`;
  }
}
