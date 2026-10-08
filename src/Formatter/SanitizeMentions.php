<?php

namespace AdminLby\Anonymous\Formatter;

use DOMDocument;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use s9e\TextFormatter\Utils;

final class SanitizeMentions
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function __invoke(Post $post): void
    {
        if (! $post instanceof CommentPost || ! $post->isDirty('content') || ! ($xml = $post->parsed_content) || ! str_contains($xml, '<POSTMENTION')) {
            return;
        }

        $ids = Utils::getAttributeValues($xml, 'POSTMENTION', 'id');
        $anonymous = Post::query()->whereIn('id', $ids)->where('is_anonymous', true)->pluck('id')->all();
        if (! $anonymous) {
            return;
        }

        $document = new DOMDocument();
        if (! $document->loadXML($xml, LIBXML_NONET)) {
            return;
        }
        $label = $this->translator->trans('adminlby-anonymous.forum.anonymous_user');
        foreach ($document->getElementsByTagName('POSTMENTION') as $mention) {
            if (in_array((int) $mention->getAttribute('id'), array_map('intval', $anonymous), true)) {
                $mention->setAttribute('displayname', $label);
                // The raw tag body is indexed too. Canonicalize both locations
                // so author-name searches cannot locate quotes of anonymous posts.
                $mention->textContent = '@"'.$label.'"#p'.$mention->getAttribute('id');
            }
        }
        $post->parsed_content = $document->saveXML($document->documentElement);
    }
}
