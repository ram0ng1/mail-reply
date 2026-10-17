import app from 'flarum/admin/app';

export const KEY = 'ramon-mail-reply.';

let afterSave: (() => void) | null = null;

export function getStr(name: string, def = ''): string {
  const value: unknown = app.data.settings[KEY + name];

  return value === undefined || value === null ? def : String(value);
}

export function getBool(name: string): boolean {
  const value: unknown = app.data.settings[KEY + name];

  return value === true || value === 1 || value === '1' || value === 'true';
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
 * botão global. Uma falha já vira alerta pelo errorHandler padrão do core.
 */
export function saveSetting(name: string, value: string): Promise<void> {
  app.data.settings[KEY + name] = value;
  m.redraw();

  return app
    .request({
      method: 'POST',
      url: app.forum.attribute<string>('apiUrl') + '/settings',
      body: { [KEY + name]: value },
    })
    .then(
      () => afterSave?.(),
      () => {}
    );
}
