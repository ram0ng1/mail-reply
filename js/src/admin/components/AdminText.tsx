import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import { getStr, KEY, saveSetting } from '../utils/settings';

export interface AdminTextAttrs extends ComponentAttrs {
  setting: string;
  label: Mithril.Children;
  help?: Mithril.Children;
  type?: 'text' | 'email' | 'password' | 'number';
  placeholder?: string;
  min?: number;
  /** Controle extra ao lado do campo, como o botão de gerar segredo. */
  addon?: Mithril.Children;
}

/**
 * Campo que grava sozinho 600 ms depois da última tecla.
 */
export default class AdminText extends Component<AdminTextAttrs> {
  timer: ReturnType<typeof setTimeout> | null = null;

  onremove() {
    if (this.timer) clearTimeout(this.timer);
  }

  view() {
    const { setting, label, help, type = 'text', placeholder, min, addon } = this.attrs;
    const id = 'MailReplyAdmin-' + setting;
    const secret = type === 'password';

    const input = (
      <input
        id={id}
        className="FormControl"
        type={type}
        value={getStr(setting)}
        placeholder={placeholder ?? ''}
        min={min}
        step={type === 'number' ? 1 : undefined}
        autocomplete={secret ? 'new-password' : 'off'}
        spellcheck={false}
        oninput={(e: InputEvent) => this.change((e.target as HTMLInputElement).value)}
      />
    );

    return (
      <div className={'Form-group' + (type === 'number' ? ' MailReplyAdmin-narrow' : '')}>
        <label className="MailReplyAdmin-label" for={id}>
          {label}
        </label>
        {addon ? (
          <div className="MailReplyAdmin-inputRow">
            {input}
            {addon}
          </div>
        ) : (
          input
        )}
        {help ? <p className="helpText">{help}</p> : null}
      </div>
    );
  }

  change(value: string) {
    const { setting } = this.attrs;

    app.data.settings[KEY + setting] = value;
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => {
      this.timer = null;
      saveSetting(setting, value);
    }, 600);
  }
}
