import Component, { type ComponentAttrs } from 'flarum/common/Component';
import { t } from '../utils/trans';
import { parseReplyAddress } from '../utils/replyAddress';

export interface AliasPreviewAttrs extends ComponentAttrs {
  replyAddress: string;
}

const SAMPLE = [
  { part: 'user', text: 'u42' },
  { part: 'discussion', text: 'd1337' },
  { part: 'post', text: 'p9001' },
  { part: 'sig', text: '-3f9a1c0be47d52e8' },
];

/**
 * Prévia ao vivo do Reply-To gerado a partir da caixa digitada, com cada
 * pedaço do +tag destacado e explicado na legenda.
 */
export default class AliasPreview extends Component<AliasPreviewAttrs> {
  view() {
    const parts = parseReplyAddress(this.attrs.replyAddress.trim());

    return (
      <div className={'MailReplyAdmin-alias' + (parts ? '' : ' is-pending')} aria-live="polite">
        <div className="MailReplyAdmin-alias-label">{t('alias_preview_label')}</div>
        <code className="MailReplyAdmin-alias-address">
          <span className="MailReplyAdmin-alias-local">{parts?.local ?? 'inbox'}</span>
          <span className="MailReplyAdmin-alias-plus">+</span>
          {SAMPLE.map(({ part, text }) => (
            <span className="MailReplyAdmin-alias-tag" data-part={part}>
              {text}
            </span>
          ))}
          <span className="MailReplyAdmin-alias-domain">@{parts?.domain ?? 'reply.example.com'}</span>
        </code>
        <ul className="MailReplyAdmin-alias-legend">
          {SAMPLE.map(({ part }) => (
            <li data-part={part}>{t('alias_legend_' + part)}</li>
          ))}
        </ul>
        <div className="MailReplyAdmin-alias-note">
          {parts ? t('alias_base', { address: <code>{`${parts.local}@${parts.domain}`}</code> }) : t('alias_pending')}
        </div>
      </div>
    );
  }
}
