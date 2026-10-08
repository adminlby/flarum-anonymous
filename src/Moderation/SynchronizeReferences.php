<?php

namespace AdminLby\Anonymous\Moderation;

use DOMDocument;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\Post;
use s9e\TextFormatter\Utils;

final class SynchronizeReferences
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function synchronize(array $ids, bool $anonymous): void
    {
        $labels = [];
        foreach (Post::query()->allTypes()->with('user')->whereIn('id', $ids)->get() as $post) {
            $labels[(int) $post->id] = $anonymous ? $this->translator->trans('adminlby-anonymous.forum.anonymous_user')
                : ($post->user?->display_name ?? $this->translator->trans('adminlby-anonymous.forum.deleted_author'));
        }
        $db = (new Post())->getConnection();
        // Rewrite only structured mentions of these posts. Ordinary prose,
        // unrelated mentions and users' own signed text are left intact.
        $db->table('posts')->where('type', 'comment')->where('content', 'like', '%<POSTMENTION%')->orderBy('id')
            ->chunkById(100, function ($rows) use ($labels, $db): void {
                foreach ($rows as $row) {
                    $content = $this->xml($row->content, $labels);
                    if ($content !== $row->content && ! $db->table('posts')->where('id', $row->id)->where('content', $row->content)->update(['content' => $content])) {
                        throw new \RuntimeException('A referenced post changed concurrently; retry the identity change.');
                    }
                }
            });

        // Diff may be disabled while its historical data still exists. Keep
        // both plain and archived revisions safe before committing anonymity.
        $schema = $db->getSchemaBuilder();
        if ($schema->hasTable('post_edit_histories')) {
            $db->table('post_edit_histories')->whereNotNull('content')->orderBy('id')->chunkById(100, function ($rows) use ($labels, $db): void {
                foreach ($rows as $row) {
                    $content = $this->tokens($row->content, $labels);
                    if ($content !== $row->content && ! $db->table('post_edit_histories')->where('id', $row->id)->where('content', $row->content)->update(['content' => $content])) {
                        throw new \RuntimeException('A referenced revision changed concurrently; retry the identity change.');
                    }
                }
            });
        }
        if ($schema->hasTable('post_edit_histories_archive')) {
            $repository = new \HuseyinFiliz\Diff\Repositories\DiffArchiveRepository();
            $db->table('post_edit_histories_archive')->orderBy('id')->chunkById(50, function ($rows) use ($labels, $db, $repository): void {
                foreach ($rows as $row) {
                    $contents = $repository->getContents($row->contents);
                    if (! is_array($contents)) throw new \RuntimeException('Invalid Diff archive; identity change was not committed.');
                    $safe = array_map(fn ($content) => is_string($content) ? $this->tokens($content, $labels) : $content, $contents);
                    if ($safe !== $contents && ! $db->table('post_edit_histories_archive')->where('id', $row->id)->where('contents', $row->contents)
                        ->update(['contents' => $repository->setContents($safe)])) {
                        throw new \RuntimeException('A referenced archive changed concurrently; retry the identity change.');
                    }
                }
            });
        }
    }

    private function xml(string $xml, array $labels): string
    {
        $referenced = array_map('intval', Utils::getAttributeValues($xml, 'POSTMENTION', 'id'));
        if (! array_intersect($referenced, array_keys($labels))) return $xml;
        $document = new DOMDocument();
        if (! $document->loadXML($xml, LIBXML_NONET)) throw new \RuntimeException('Invalid referenced post markup; identity change was not committed.');
        foreach ($document->getElementsByTagName('POSTMENTION') as $mention) {
            $id = (int) $mention->getAttribute('id');
            if (! isset($labels[$id])) continue;
            $mention->setAttribute('displayname', $labels[$id]);
            $mention->textContent = '@"'.$labels[$id].'"#p'.$id;
        }

        return $document->saveXML($document->documentElement);
    }

    private function tokens(string $raw, array $labels): string
    {
        return preg_replace_callback('/@"[^"\r\n]*"#p?(\d+)/u', fn (array $match) => isset($labels[(int) $match[1]])
            ? '@"'.$labels[(int) $match[1]].'"#p'.$match[1] : $match[0], $raw);
    }
}
