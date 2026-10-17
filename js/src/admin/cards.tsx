import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { SubDivider } from './components/AdminCard';
import ActivityFeed from './components/ActivityFeed';
import ActivityStats from './components/ActivityStats';
import AdminSelect from './components/AdminSelect';
import AdminText from './components/AdminText';
import AdminToggle from './components/AdminToggle';
import AliasPreview from './components/AliasPreview';
import ImapTest from './components/ImapTest';
import PipeScript from './components/PipeScript';
import Providers from './components/Providers';
import StatusLine from './components/StatusLine';
import type StatusState from './utils/StatusState';
import { getStr, saveSetting } from './utils/settings';
import { t, ts } from './utils/trans';
import { DELIVERY_MODES, toDeliveryMode, type DeliveryMode } from './types';

export type GroupKey = 'general' | 'receiving' | 'security' | 'appearance' | 'activity';

export const mode = (): DeliveryMode => toDeliveryMode(getStr('delivery_mode', 'imap'));

const enabled = (): boolean => mode() !== 'disabled';

export interface GroupDef {
  key: GroupKey;
  icon: string;
  visible?: () => boolean;
}

/**
 * Receber, segurança e atividade não fazem sentido com a resposta por e-mail
 * desligada; as abas somem em vez de ficarem vazias.
 */
export const GROUPS: GroupDef[] = [
  { key: 'general', icon: 'fas fa-cog' },
  { key: 'receiving', icon: 'fas fa-inbox', visible: enabled },
  { key: 'security', icon: 'fas fa-shield-halved', visible: enabled },
  { key: 'appearance', icon: 'fas fa-palette' },
  { key: 'activity', icon: 'fas fa-chart-line', visible: enabled },
];

export interface CardDef {
  id: string;
  group: GroupKey;
  icon: string;
  /** Palavras extras para a busca: o que o admin digitaria procurando o card. */
  keywords: string;
  body: (status: StatusState) => Mithril.Children;
  visible?: () => boolean;
}

/**
 * Segredo de 32 bytes em hex, o mesmo formato de `openssl rand -hex 32`.
 */
function randomSecret(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(32));

  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

const inboundUrl = (): string => (app.forum.attribute<string>('apiUrl') || '') + '/mail-reply/inbound';

const options = (keys: readonly string[], prefix: string, suffix: string): Record<string, string> =>
  Object.fromEntries(keys.map((key) => [key, ts(prefix + key + suffix)]));

export const CARDS: CardDef[] = [
  {
    id: 'mode',
    group: 'general',
    icon: 'fas fa-route',
    keywords: 'modo mode entrega delivery imap webhook pipe desligado disabled status saúde health',
    body: (status) => [
      <AdminSelect
        setting="delivery_mode"
        default="imap"
        label={t('delivery_mode_label')}
        help={t('mode_' + mode() + '_detail')}
        options={options(DELIVERY_MODES, 'mode_', '_title')}
      />,
      <SubDivider />,
      <StatusLine state={status} />,
    ],
  },
  {
    id: 'mailbox',
    group: 'general',
    icon: 'fas fa-at',
    visible: enabled,
    keywords: 'caixa mailbox endereço address reply-to alias e-mail email',
    body: () => [
      <AdminText
        setting="reply_address"
        type="email"
        label={t('reply_address_label')}
        help={t('reply_address_help')}
        placeholder="inbox@reply.example.com"
      />,
      <AliasPreview replyAddress={getStr('reply_address')} />,
    ],
  },

  {
    id: 'imap_server',
    group: 'receiving',
    icon: 'fas fa-server',
    visible: () => mode() === 'imap',
    keywords: 'imap servidor server host porta port criptografia encryption ssl tls pasta folder mailbox',
    body: () => (
      <div className="MailReplyAdmin-fieldGrid">
        <AdminText setting="imap_host" label={t('imap_host_label')} help={t('imap_host_help')} placeholder="imap.example.com" />
        <AdminText setting="imap_port" type="number" min={1} label={t('imap_port_label')} help={t('imap_port_help')} placeholder="993" />
        <AdminSelect
          setting="imap_encryption"
          default="ssl"
          label={t('imap_encryption_label')}
          options={options(['ssl', 'tls', 'none'], 'imap_encryption_', '')}
        />
        <AdminText setting="imap_mailbox" label={t('imap_mailbox_label')} placeholder="INBOX" />
      </div>
    ),
  },
  {
    id: 'imap_auth',
    group: 'receiving',
    icon: 'fas fa-key',
    visible: () => mode() === 'imap',
    keywords: 'imap autenticação authentication usuário username login senha password',
    body: () => (
      <div className="MailReplyAdmin-fieldGrid">
        <AdminText setting="imap_username" label={t('imap_username_label')} placeholder="inbox@reply.example.com" />
        <AdminText setting="imap_password" type="password" label={t('imap_password_label')} help={t('imap_password_help')} placeholder="••••••••" />
      </div>
    ),
  },
  {
    id: 'imap_options',
    group: 'receiving',
    icon: 'fas fa-sliders',
    visible: () => mode() === 'imap',
    keywords: 'imap opções options apagar delete certificado certificate tls validar validate',
    body: () => [
      <AdminToggle setting="imap_delete_after" label={t('imap_delete_after_label')} help={t('imap_delete_after_help')} />,
      <AdminToggle setting="imap_validate_cert" label={t('imap_validate_cert_label')} help={t('imap_validate_cert_help')} />,
    ],
  },
  {
    id: 'imap_test',
    group: 'receiving',
    icon: 'fas fa-plug-circle-check',
    visible: () => mode() === 'imap',
    keywords: 'imap testar test conexão connection',
    body: () => <ImapTest />,
  },
  {
    id: 'webhook_secret',
    group: 'receiving',
    icon: 'fas fa-lock',
    visible: () => mode() === 'webhook',
    keywords: 'webhook segredo secret token header x-mail-reply-token gerar generate',
    body: () => (
      <AdminText
        setting="webhook_secret"
        label={t('webhook_secret_label')}
        help={t('webhook_secret_help')}
        placeholder="••••••••"
        addon={
          <Button className="Button" icon="fas fa-dice" onclick={() => saveSetting('webhook_secret', randomSecret())}>
            {t('webhook_secret_generate')}
          </Button>
        }
      />
    ),
  },
  {
    id: 'providers',
    group: 'receiving',
    icon: 'fas fa-cloud',
    visible: () => mode() === 'webhook',
    keywords: 'provedores providers mailgun postmark cloudflare url signing key assinatura',
    body: () => <Providers inboundUrl={inboundUrl()} />,
  },
  {
    id: 'pipe_script',
    group: 'receiving',
    icon: 'fas fa-code',
    visible: () => mode() === 'webhook',
    keywords: 'script php pipe mta directadmin cpanel exim encaminhador forwarder',
    body: () => <PipeScript inboundUrl={inboundUrl()} />,
  },

  {
    id: 'signature',
    group: 'security',
    icon: 'fas fa-signature',
    keywords: 'assinatura signature hmac segredo secret chave key alias',
    body: () => <AdminText setting="secret" type="password" label={t('secret_label')} help={t('secret_help')} />,
  },
  {
    id: 'sender',
    group: 'security',
    icon: 'fas fa-user-check',
    keywords: 'remetente sender from conferir match confirmado confirmed',
    body: () => <AdminToggle setting="require_sender_match" label={t('require_sender_match_label')} help={t('require_sender_match_help')} />,
  },
  {
    id: 'limits',
    group: 'security',
    icon: 'fas fa-gauge',
    keywords: 'limite limit hora hour respostas replies spam',
    body: () => (
      <AdminText
        setting="max_replies_per_hour"
        type="number"
        min={0}
        label={t('max_replies_per_hour_label')}
        help={t('max_replies_per_hour_help')}
        placeholder="20"
      />
    ),
  },
  {
    id: 'rejections',
    group: 'security',
    icon: 'fas fa-envelope-circle-check',
    keywords: 'rejeição rejection aviso notice recusada rejected autor author',
    body: () => <AdminToggle setting="send_rejection_notices" label={t('send_rejection_notices_label')} help={t('send_rejection_notices_help')} />,
  },

  {
    id: 'badge',
    group: 'appearance',
    icon: 'fas fa-certificate',
    keywords: 'selo badge via email aparência appearance post',
    body: () => <AdminToggle setting="show_badge" label={t('show_badge_label')} help={t('show_badge_help')} />,
  },

  {
    id: 'stats',
    group: 'activity',
    icon: 'fas fa-chart-simple',
    keywords: 'contadores counters estatísticas stats 24h 7 dias days',
    body: (status) => <ActivityStats state={status} />,
  },
  {
    id: 'feed',
    group: 'activity',
    icon: 'fas fa-stream',
    keywords: 'atividade activity recentes recent mensagens messages rejeitadas rejected ignoradas ignored log',
    body: (status) => <ActivityFeed state={status} />,
  },
];

export const groupVisible = (group: GroupKey): boolean => GROUPS.find((g) => g.key === group)?.visible?.() ?? true;

export const cardVisible = (card: CardDef): boolean => groupVisible(card.group) && (card.visible?.() ?? true);
