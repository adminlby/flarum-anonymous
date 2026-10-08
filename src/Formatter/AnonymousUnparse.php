<?php

namespace AdminLby\Anonymous\Formatter;

use AdminLby\Anonymous\Privacy;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\Post;

final class AnonymousUnparse
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function __invoke(mixed $context, ?string $xml): ?string
    {
        if ($xml === null) {
            return null;
        }
        // Runs after mentions' unparser has produced raw mention tokens.
        return preg_replace_callback('/@"[^"\n]*"#p?(\d+)/u', function (array $match) use ($context): string {
            $post = $context instanceof Post && $context->relationLoaded('mentionsPosts')
                ? $context->mentionsPosts->find($match[1]) : Post::query()->find($match[1]);

            return $post && Privacy::post($post)
                ? '@"'.$this->translator->trans('adminlby-anonymous.forum.anonymous_user').'"#p'.$match[1]
                : $match[0];
        }, $xml);
    }
}
