import app from 'flarum/admin/app';
import { ts } from './trans';

function legacyCopy(text: string): boolean {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();

  try {
    return document.execCommand('copy');
  } catch {
    return false;
  } finally {
    document.body.removeChild(ta);
  }
}

/**
 * Copia o texto e avisa o admin do resultado; usa execCommand quando a
 * Clipboard API não existe (HTTP sem TLS, navegadores antigos).
 */
export default function copyToClipboard(text: string, successKey: string): void {
  const done = () => app.alerts.show({ type: 'success' }, ts(successKey));
  const failed = () => app.alerts.show({ type: 'error' }, ts('copy_failed'));

  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(text).then(done, () => (legacyCopy(text) ? done() : failed()));
    return;
  }

  legacyCopy(text) ? done() : failed();
}
