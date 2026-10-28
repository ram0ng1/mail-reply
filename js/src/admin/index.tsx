import app from 'flarum/admin/app';
import addHideExtensionSubmitButton from './addHideExtensionSubmitButton';

export { default as extend } from './extend';

app.initializers.add('ramon-mail-reply', () => {
  addHideExtensionSubmitButton();
});
