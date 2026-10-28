import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import { getStr, isSealed, KEY, saveSetting } from '../utils/settings';
import { ts } from '../utils/trans';

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
  pending: string | null = null;

  /**
   * Trocar de aba ou buscar desmonta o campo: o que foi digitado nos
   * últimos 600 ms é gravado na hora em vez de se perder.
   */
  onremove() {
    if (this.timer) clearTimeout(this.timer);
    if (this.pending !== null) saveSetting(this.attrs.setting, this.pending);
  }

  view() {
    const { setting, label, help, type = 'text', placeholder, min, addon } = this.attrs;
    const id = 'MailReplyAdmin-' + setting;
    const secret = type === 'password';
    const stored = getStr(setting);
    // O valor salvo de um segredo é o texto cifrado: mostrá-lo no campo
    // convidaria a editar o cifrado, que deixaria de abrir.
    const sealed = secret && isSealed(stored);

    const input = (
      <input
        id={id}
        className="FormControl"
        type={type}
        value={sealed ? '' : stored}
        placeholder={sealed ? ts('secret_saved_placeholder') : (placeholder ?? '')}
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
    const { setting, type } = this.attrs;

    if (this.timer) clearTimeout(this.timer);

    // Apagar o campo de um segredo já salvo não o remove: sem isso, um
    // descuido trocaria a chave HMAC e invalidaria os aliases já enviados.
    if (type === 'password' && value === '' && isSealed(getStr(setting))) {
      this.pending = null;
      return;
    }

    app.data.settings[KEY + setting] = value;
    this.pending = value;
    this.timer = setTimeout(() => {
      this.timer = null;
      this.pending = null;
      saveSetting(setting, value);
    }, 600);
  }
}
