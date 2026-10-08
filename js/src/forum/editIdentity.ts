// The server's isMine describes the original anonymous author without a User
// relationship. It is sufficient to lock that author's editing identity.
export function originalAnonymousAuthor(post: {attribute: (key: string) => unknown}): boolean {
  return !!post.attribute('isAnonymous') && !!post.attribute('isMine');
}
