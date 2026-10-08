<?php

namespace AdminLby\Anonymous\Api;

use AdminLby\Anonymous\EditHistory\RevisionPrivacy;
use AdminLby\Anonymous\Event\EditorRevealed;
use Flarum\Http\RequestUtil;
use HuseyinFiliz\Diff\Models\Diff;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RevealEditorController implements RequestHandlerInterface
{
    public function __construct(private Dispatcher $events)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertPermission($actor->hasPermission('anonymous.reveal'));
        $actor->assertCan('viewEditHistory');
        $revision = Diff::query()->whereHas('post', function (Builder $query) use ($actor): void {
            $query->whereVisibleTo($actor);
            if (! $actor->hasPermission('discussion.hidePosts')) {
                $query->where(fn (Builder $posts) => $posts->whereNull('hidden_at')->orWhere('user_id', $actor->id));
            }
        })->findOrFail((int) ($request->getQueryParams()['id'] ?? 0));
        $actor->assertPermission(RevisionPrivacy::anonymous($revision));
        $editor = $revision->actor;
        $editorId = RevisionPrivacy::editorId($revision);
        $this->events->dispatch(new EditorRevealed($actor, $revision, $editorId));

        return new JsonResponse(['data' => [
            'type' => 'anonymous-edit-author-reveals', 'id' => (string) $revision->id,
            'attributes' => ['userId' => $editorId, 'displayName' => $editor?->display_name,
                'username' => $editor?->username, 'deleted' => $editor === null],
        ]], 200, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'Vary' => 'Cookie, Authorization']);
    }
}
