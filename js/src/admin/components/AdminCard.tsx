import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';

export interface AdminCardAttrs extends ComponentAttrs {
  title: string;
  icon: string;
}

export default class AdminCard extends Component<AdminCardAttrs> {
  view(vnode: Mithril.Vnode<AdminCardAttrs, this>) {
    const { title, icon } = this.attrs;

    return (
      <div className="MailReplyAdmin-card">
        <div className="MailReplyAdmin-card-header">
          <span className="MailReplyAdmin-card-icon">
            <Icon name={icon} />
          </span>
          <h3 className="MailReplyAdmin-card-title">{title}</h3>
        </div>
        <div className="MailReplyAdmin-card-body">{vnode.children}</div>
      </div>
    );
  }
}

export class SubDivider extends Component {
  view() {
    return <div className="MailReplyAdmin-subDivider" />;
  }
}
