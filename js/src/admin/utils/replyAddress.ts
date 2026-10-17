export interface ReplyAddressParts {
  local: string;
  domain: string;
}

/**
 * Separa `local+tag@domain` em partes, descartando o `+tag`; devolve null
 * quando o valor ainda não é um endereço.
 */
export function parseReplyAddress(value: string): ReplyAddressParts | null {
  const at = value.lastIndexOf('@');
  if (at <= 0 || at === value.length - 1) return null;

  const local = value.slice(0, at).split('+')[0];
  const domain = value.slice(at + 1);

  return local ? { local, domain } : null;
}

export function escapeRegex(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
