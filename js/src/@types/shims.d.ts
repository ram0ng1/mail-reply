import Post from 'flarum/common/models/Post';

declare module 'flarum/common/models/Post' {
  export default interface Post {
    viaMailReply(): boolean;
  }
}
