import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import { override } from 'flarum/common/extend';

export { default as extend } from './extend';

/**
 * Todos os controles salvam sozinhos; o botão Salvar nativo ficaria sempre
 * desabilitado e confundiria, então sai só desta página.
 */
app.initializers.add('ramon-mail-reply', () => {
  override(ExtensionPage.prototype, 'submitButton', function (this: ExtensionPage, original: () => unknown) {
    return this.extension?.id === 'ramon-mail-reply' ? null : original();
  });
});
