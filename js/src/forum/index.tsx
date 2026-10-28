import app from 'flarum/forum/app';
import addViaMailBadge from './addViaMailBadge';

export { default as extend } from './extend';

app.initializers.add('ramon-mail-reply', () => {
  addViaMailBadge();
});
