import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Switch from 'flarum/common/components/Switch';
import type Mithril from 'mithril';
import { getBool, saveSetting } from '../utils/settings';

export interface AdminToggleAttrs extends ComponentAttrs {
  setting: string;
  label: Mithril.Children;
  help?: Mithril.Children;
}

/**
 * Switch que grava na hora como '1'/'0'.
 */
export default class AdminToggle extends Component<AdminToggleAttrs> {
  view() {
    const { setting, label, help } = this.attrs;

    return (
      <div className="Form-group MailReplyAdmin-toggle">
        <Switch state={getBool(setting)} onchange={(checked: boolean) => saveSetting(setting, checked ? '1' : '0')}>
          {label}
        </Switch>
        {help ? <p className="helpText">{help}</p> : null}
      </div>
    );
  }
}
