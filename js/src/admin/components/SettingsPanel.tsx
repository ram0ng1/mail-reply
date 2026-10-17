import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import Icon from 'flarum/common/components/Icon';
import AdminCard from './AdminCard';
import StatusState from '../utils/StatusState';
import { onSettingSaved } from '../utils/settings';
import { t, ts } from '../utils/trans';
import { CARDS, cardVisible, GROUPS, groupVisible, type CardDef, type GroupKey } from '../cards';

const TAB_STORAGE_KEY = 'ramon-mail-reply.admin.tab';

/**
 * Aba ativa, lembrada entre visitas: cada gravação redesenha a página e
 * voltar sempre para a primeira aba seria perder o lugar.
 */
let activeGroup: GroupKey = (() => {
  try {
    const saved = localStorage.getItem(TAB_STORAGE_KEY) as GroupKey | null;
    if (saved && GROUPS.some((g) => g.key === saved)) return saved;
  } catch {
    return 'general';
  }
  return 'general';
})();

let query = '';

function setGroup(key: GroupKey): void {
  activeGroup = key;
  query = '';
  try {
    localStorage.setItem(TAB_STORAGE_KEY, key);
  } catch {
    return;
  }
}

const cardTitle = (card: CardDef): string => ts('card_' + card.id + '_title');

const matchesQuery = (card: CardDef, q: string): boolean => `${cardTitle(card)} ${card.keywords}`.toLowerCase().includes(q);

/**
 * Casca da página: abas por grupo, busca que varre os cards visíveis de
 * todos os grupos e os cards do grupo ativo. Cada controle salva sozinho.
 */
export default class SettingsPanel extends Component {
  status = new StatusState();

  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>) {
    super.oninit(vnode);
    onSettingSaved(() => this.status.reloadSoon());
    this.status.load();
  }

  view() {
    if (!groupVisible(activeGroup)) setGroup('general');

    const q = query.trim().toLowerCase();
    const candidates = CARDS.filter(cardVisible);
    const shown = q ? candidates.filter((card) => matchesQuery(card, q)) : candidates.filter((card) => card.group === activeGroup);

    return (
      <div className="MailReplyAdmin-settings">
        <div className="MailReplyAdmin-toolbar">
          <nav className="MailReplyAdmin-tabs" role="tablist" aria-label={ts('groups_aria')}>
            {GROUPS.filter((g) => groupVisible(g.key)).map((g) => {
              const isActive = !q && g.key === activeGroup;

              return (
                <button
                  key={g.key}
                  type="button"
                  role="tab"
                  aria-selected={isActive ? 'true' : 'false'}
                  className={'MailReplyAdmin-tab' + (isActive ? ' is-active' : '')}
                  onclick={() => setGroup(g.key)}
                >
                  <Icon name={g.icon} />
                  <span>{t('group_' + g.key)}</span>
                  <span className="MailReplyAdmin-tabCount">{candidates.filter((card) => card.group === g.key).length}</span>
                </button>
              );
            })}
          </nav>

          <div className="MailReplyAdmin-search">
            <Icon name="fas fa-search" />
            <input
              type="search"
              className="MailReplyAdmin-searchInput"
              value={query}
              placeholder={ts('search_placeholder')}
              aria-label={ts('search_placeholder')}
              oninput={(e: InputEvent) => (query = (e.target as HTMLInputElement).value)}
            />
            {query ? (
              <button type="button" className="MailReplyAdmin-searchClear" aria-label={ts('search_clear')} onclick={() => (query = '')}>
                <Icon name="fas fa-times" />
              </button>
            ) : null}
          </div>
        </div>

        {shown.length === 0 ? (
          <p className="MailReplyAdmin-empty">{t('search_empty')}</p>
        ) : (
          shown.map((card) => (
            <AdminCard key={card.id} title={cardTitle(card)} icon={card.icon}>
              {card.body(this.status)}
            </AdminCard>
          ))
        )}
      </div>
    );
  }
}
