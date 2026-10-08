<?php

namespace AdminLby\Anonymous\Formatter;

use AdminLby\Anonymous\Privacy;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\Post;
use s9e\TextFormatter\Renderer;
use s9e\TextFormatter\Utils;

final class AnonymousMentions
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function __invoke(Renderer $renderer, mixed $context, string $xml): string
    {
        return Utils::replaceAttributes($xml, 'POSTMENTION', function (array $attributes) use ($context): array {
            $post = $context instanceof Post && $context->relationLoaded('mentionsPosts')
                ? $context->mentionsPosts->find($attributes['id'] ?? 0)
                : Post::query()->find($attributes['id'] ?? 0);
            if ($post && Privacy::post($post)) {
                $attributes['displayname'] = $this->translator->trans('adminlby-anonymous.forum.anonymous_user');
            }

            return $attributes;
        });
    }
}
