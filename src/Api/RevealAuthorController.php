<?php

namespace AdminLby\Anonymous\Api;

use AdminLby\Anonymous\Event\AuthorRevealed;
use AdminLby\Anonymous\Privacy;
use Flarum\Http\RequestUtil;
use Flarum\Post\PostRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RevealAuthorController implements RequestHandlerInterface
{
    public function __construct(private PostRepository $posts, private Dispatcher $events)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $post = $this->posts->findOrFail((int) ($request->getQueryParams()['id'] ?? 0), $actor);
        $actor->assertCan('revealAnonymousAuthor', $post);
        $author = $post->user;
        $this->events->dispatch(new AuthorRevealed($actor, $post));

        return new JsonResponse(['data' => [
            'type' => 'anonymous-author-reveals',
            'id' => (string) $post->id,
            'attributes' => [
                'userId' => Privacy::authorId($post),
                'displayName' => $author?->display_name,
                'username' => $author?->username,
                'deleted' => $author === null,
            ],
        ]], 200, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'Vary' => 'Cookie, Authorization']);
    }
}
