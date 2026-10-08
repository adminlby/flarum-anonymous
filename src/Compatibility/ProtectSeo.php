<?php

namespace AdminLby\Anonymous\Compatibility;

use AdminLby\Anonymous\Privacy;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\Seo\Event\PreparingPageMeta;
use FoF\Seo\Support\AuthorSchema;
use FoF\Seo\Support\PostMedia;

final class ProtectSeo
{
    public function __construct(private SlugManager $slugs, private UrlGenerator $url, private TranslatorInterface $translator,
        private AuthorSchema $authors, private SettingsRepositoryInterface $settings, private ExtensionManager $extensions)
    {
    }

    public function __invoke(PreparingPageMeta $event): void
    {
        if ($event->request->getAttribute('routeName') !== 'discussion') return;
        $slug = $event->request->getQueryParams()['id'] ?? null;
        if ($slug === null) return;
        try {
            $discussion = $this->slugs->forResource(Discussion::class)->fromSlug($slug, RequestUtil::getActor($event->request));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { return; }

        if (Privacy::discussion($discussion)) $event->properties->setSchemaJson('author', $this->anonymousAuthor());
        $posts = $discussion->posts()->where('number', '>', 1)->where('type', 'comment')->where('is_private', false)
            ->whereVisibleTo(RequestUtil::getActor($event->request))->with('user')->orderBy('number');
        $likes = $this->extensions->isEnabled('flarum-likes');
        $gamification = $this->extensions->isEnabled('fof-gamification');
        if ($likes) $posts->withCount('likes');
        if ($gamification) $posts->withCount('upvotes');
        $limit = (int) $this->settings->get('seo_post_crawler_limit', 100);
        if ($limit > 0) $posts->limit($limit);
        $rows = $posts->get();
        if (! $rows->contains(fn ($post) => Privacy::post($post))) return;
        // Rebuild the public Comment author nodes through SEO's supported
        // event/properties and media helpers; never clear Eloquent User models.
        $comments = [];
        foreach ($rows as $post) {
            if (! $post instanceof CommentPost) continue;
            $media = PostMedia::schemaFields($post->formatContent());
            if ($media === []) continue;
            $comment = [
                '@type' => 'Comment', 'datePublished' => $post->created_at->toIso8601String(),
                'dateCreated' => $post->created_at->toIso8601String(),
                'url' => $this->url->to('forum')->route('discussion', ['id' => $this->slugs->forResource(Discussion::class)->toSlug($discussion), 'near' => $post->number]).'#post-'.$post->id,
                'author' => Privacy::post($post) ? $this->anonymousAuthor() : $this->authors->forUser($post->user),
            ] + $media;
            if ($likes || $gamification) $comment['interactionStatistic'] = [
                '@type' => 'InteractionCounter', 'interactionType' => 'https://schema.org/LikeAction',
                'userInteractionCount' => (int) $post->getAttribute('likes_count') + (int) $post->getAttribute('upvotes_count'),
            ];
            $comments[] = $comment;
        }
        $event->properties->setSchemaJson('comment', $comments);
    }

    private function anonymousAuthor(): array
    {
        return ['@type' => 'Person', 'name' => $this->translator->trans('adminlby-anonymous.forum.anonymous_user')];
    }
}
