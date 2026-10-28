import app from 'flarum/admin/app';

export const KEY = 'ramon-mail-reply.';

let afterSave: (() => void) | null = null;

/** Último valor confirmado pelo servidor, para desfazer um save que falhou. */
const saved: Record<string, string> = {};

/**
 * Guarda os valores salvos ao abrir a página; um save que falhar volta para
 * eles.
 */
export function snapshotSettings(): void {
  for (const key of Object.keys(app.data.settings)) {
    if (key.startsWith(KEY)) saved[key.slice(KEY.length)] = app.data.settings[key];
  }
}

export function getStr(name: string, def = ''): string {
  const value: unknown = app.data.settings[KEY + name];

  return value === undefined || value === null ? def : String(value);
}

export function getBool(name: string): boolean {
  const value: unknown = app.data.settings[KEY + name];

  return value === true || value === 1 || value === '1' || value === 'true';
}

/**
 * Segredo já cifrado pelo servidor (`mp1:`/`mp2:` do SecretBox).
 */
export function isSealed(value: string): boolean {
  return /^mp[12]:/.test(value);
}

/**
 * Registra o callback chamado depois de cada gravação bem-sucedida; a página
 * usa para recarregar o status, que depende do que está salvo.
 */
export function onSettingSaved(callback: () => void): void {
  afterSave = callback;
}

/**
 * Atualiza o valor local e grava na hora: os controles salvam sozinhos, sem
 * botão global. Uma falha vira alerta pelo errorHandler padrão do core e o
 * controle volta ao valor salvo.
 */
export function saveSetting(name: string, value: string): Promise<void> {
  const previous = name in saved ? saved[name] : app.data.settings[KEY + name];
  app.data.settings[KEY + name] = value;
  m.redraw();

  return app
    .request({
      method: 'POST',
      url: app.forum.attribute<string>('apiUrl') + '/settings',
      body: { [KEY + name]: value },
    })
    .then(
      () => {
        saved[name] = value;
        afterSave?.();
      },
      () => {
        app.data.settings[KEY + name] = previous;
        m.redraw();
      }
    );
}
