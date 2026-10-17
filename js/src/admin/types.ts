export type DeliveryMode = 'imap' | 'webhook' | 'disabled';

export const DELIVERY_MODES: readonly DeliveryMode[] = ['imap', 'webhook', 'disabled'];

export function toDeliveryMode(value: string): DeliveryMode {
  return (DELIVERY_MODES as readonly string[]).includes(value) ? (value as DeliveryMode) : 'imap';
}

export type InboundStatus = 'processed' | 'rejected' | 'ignored';

export const INBOUND_STATUSES: readonly InboundStatus[] = ['processed', 'rejected', 'ignored'];

export type StatusCounts = Record<InboundStatus, number>;

export interface RecentEntry {
  id: number;
  status: InboundStatus;
  reason: string | null;
  createdAt: string;
  user: { id: number; username: string } | null;
  discussion: { id: number; title: string; slug: string } | null;
  postNumber: number | null;
}

export interface MailReplyStatus {
  mode: DeliveryMode;
  configured: {
    reply_address: boolean;
    imap: boolean;
    imap_library: boolean;
    webhook_secret: boolean;
    mailgun_signing_key: boolean;
  };
  polling: {
    queue: 'sync' | 'async';
    loop_alive: boolean;
    last_fetch: null | { at: number; processed: number; failed: number; skipped: number };
  };
  stats: { day: StatusCounts; week: StatusCounts };
  recent: RecentEntry[];
}

/**
 * Deriva do status salvo o que ainda falta configurar para o modo ativo;
 * desligado não precisa de nada.
 */
export function missingFor(status: MailReplyStatus): string[] {
  const { configured, mode } = status;
  if (mode === 'disabled') return [];

  const missing: string[] = [];
  if (!configured.reply_address) missing.push('reply_address');

  if (mode === 'imap') {
    if (!configured.imap_library) missing.push('imap_library');
    if (!configured.imap) missing.push('imap');
  }

  if (mode === 'webhook' && !configured.webhook_secret && !configured.mailgun_signing_key) missing.push('webhook_auth');

  return missing;
}
