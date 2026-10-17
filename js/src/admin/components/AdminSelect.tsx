import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import { getStr, saveSetting } from '../utils/settings';

export interface AdminSelectAttrs extends ComponentAttrs {
  setting: string;
  label: Mithril.Children;
  help?: Mithril.Children;
  options: Record<string, string>;
  default: string;
}

export default class AdminSelect extends Component<AdminSelectAttrs> {
  view() {
    const { setting, label, help, options } = this.attrs;
    const value = getStr(setting, this.attrs.default);
    const id = 'MailReplyAdmin-' + setting;

    return (
      <div className="Form-group">
        <label className="MailReplyAdmin-label" for={id}>
          {label}
        </label>
        <div className="MailReplyAdmin-select-wrap">
          <select
            id={id}
            className="FormControl MailReplyAdmin-select"
            value={value}
            onchange={(e: Event) => saveSetting(setting, (e.target as HTMLSelectElement).value)}
          >
            {Object.keys(options).map((key) => (
              <option key={key} value={key}>
                {options[key]}
              </option>
            ))}
          </select>
          <Icon name="fas fa-chevron-down" className="MailReplyAdmin-select-chevron" />
        </div>
        {help ? <p className="helpText">{help}</p> : null}
      </div>
    );
  }
}
