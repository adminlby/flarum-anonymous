<?php

namespace AdminLby\Anonymous\Api;

use AdminLby\Anonymous\Moderation\ChangeIdentity;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\PostRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ChangeIdentityController implements RequestHandlerInterface
{
    public function __construct(private PostRepository $posts, private ChangeIdentity $change, private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $post = $this->posts->findOrFail((int) ($request->getQueryParams()['id'] ?? 0), $actor);
        $actor->assertCan('changeAnonymousIdentity', $post);
        $body = $request->getParsedBody();
        $anonymous = is_array($body) ? ($body['data']['attributes']['isAnonymous'] ?? null) : null;
        if (! is_bool($anonymous)) {
            throw new ValidationException(['isAnonymous' => $this->translator->trans('adminlby-anonymous.api.invalid_identity')]);
        }

        $result = ($this->change)($post, $actor, $anonymous);

        // A separate response contains no author relationship. The browser
        // reloads its resources, including notification and history stores.
        return new JsonResponse(['data' => [
            'type' => 'anonymous-identity-changes', 'id' => (string) $post->id, 'attributes' => $result,
        ]], 200, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'Vary' => 'Cookie, Authorization']);
    }
}
