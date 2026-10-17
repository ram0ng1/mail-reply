import app from 'flarum/admin/app';

const PREFIX = 'ramon-mail-reply.admin.settings.';

/**
 * Traduz uma chave relativa a `ramon-mail-reply.admin.settings.`. Uma
 * tradução da comunidade com ICU quebrado devolve a chave em vez de derrubar
 * a página inteira.
 */
export function t(key: string, params: Record<string, unknown> = {}) {
  try {
    return app.translator.trans(PREFIX + key, params);
  } catch {
    return PREFIX + key;
  }
}

/**
 * Variante que devolve string pura, para atributos HTML, alerts e busca.
 */
export function ts(key: string, params: Record<string, unknown> = {}): string {
  try {
    return app.translator.trans(PREFIX + key, params, true);
  } catch {
    return PREFIX + key;
  }
}

export function hasKey(key: string): boolean {
  return PREFIX + key in app.translator.translations;
}
