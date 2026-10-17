import app from 'flarum/admin/app';
import type { MailReplyStatus } from '../types';

/**
 * Estado compartilhado entre a linha de status e a aba de atividade; uma
 * falha vira `error` para a UI mostrar inline, sem o modal global do core.
 */
export default class StatusState {
  data: MailReplyStatus | null = null;
  loading = false;
  error = false;
  timer: ReturnType<typeof setTimeout> | null = null;

  load(): Promise<void> {
    if (this.loading) return Promise.resolve();

    this.loading = true;
    this.error = false;

    return app
      .request<MailReplyStatus>({
        method: 'GET',
        url: app.forum.attribute<string>('apiUrl') + '/mail-reply/status',
        errorHandler: () => {},
      })
      .then(
        (data) => {
          this.data = data;
        },
        () => {
          this.error = true;
        }
      )
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  /**
   * Agrupa recargas disparadas por gravações em sequência numa só.
   */
  reloadSoon(): void {
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => {
      this.timer = null;
      this.load();
    }, 400);
  }
}
