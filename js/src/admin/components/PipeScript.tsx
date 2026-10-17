import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import buildPhpScript from '../utils/buildPhpScript';
import copyToClipboard from '../utils/copyToClipboard';
import { getStr } from '../utils/settings';
import { t } from '../utils/trans';

export interface PipeScriptAttrs extends ComponentAttrs {
  inboundUrl: string;
}

const SCRIPT_PATH = '/usr/local/bin/forward-to-flarum.php';
const PIPE_COMMAND = '|/usr/local/bin/php ' + SCRIPT_PATH;

/**
 * Script de pipe para o MTA. Depende do segredo do webhook: sem ele o script
 * não autentica, então só a dica aparece.
 */
export default class PipeScript extends Component<PipeScriptAttrs> {
  view() {
    const secret = getStr('webhook_secret');
    if (!secret) return <p className="MailReplyAdmin-hint">{t('webhook_script_needs_secret')}</p>;

    const script = buildPhpScript(this.attrs.inboundUrl, secret);

    return (
      <>
        <p className="helpText MailReplyAdmin-intro">{t('webhook_script_help')}</p>
        <div className="MailReplyAdmin-script-actions">
          <Button className="Button Button--primary" icon="fas fa-copy" onclick={() => copyToClipboard(script, 'webhook_script_copied')}>
            {t('webhook_script_copy')}
          </Button>
          <a className="Button hasIcon" href={'data:application/x-php;charset=utf-8,' + encodeURIComponent(script)} download="forward-to-flarum.php">
            <Icon name="fas fa-download" className="Button-icon" />
            <span className="Button-label">{t('webhook_script_download')}</span>
          </a>
        </div>

        <details className="MailReplyAdmin-details">
          <summary>{t('webhook_script_view')}</summary>
          <pre className="MailReplyAdmin-script-code">
            <code>{script}</code>
          </pre>
        </details>

        <div className="MailReplyAdmin-script-steps">
          <strong>{t('webhook_install_title')}</strong>
          <ol>
            <li>
              {t('webhook_install_step1')} <code>{SCRIPT_PATH}</code>
            </li>
            <li>
              <code>chmod 755</code> + <code>chown root:mail</code>
            </li>
            <li>
              {t('webhook_install_step3')} <code>{PIPE_COMMAND}</code>
              <p className="MailReplyAdmin-script-note">{t('webhook_install_step3_note', { code: <code /> })}</p>
              <p className="MailReplyAdmin-script-note">{t('webhook_install_step3_cpanel')}</p>
            </li>
            <li>{t('webhook_install_step4', { log: <code />, expect: <code /> })}</li>
          </ol>
        </div>
      </>
    );
  }
}
