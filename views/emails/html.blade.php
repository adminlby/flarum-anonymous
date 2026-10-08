<p>{{ $translator->trans('adminlby-anonymous.email.body', ['{title}' => $blueprint->source->discussion->title]) }}</p>
<p><a href="{{ $url->to('forum')->route('discussion', ['id' => $blueprint->source->discussion_id]) }}/{{ $blueprint->source->number }}">{{ $translator->trans('adminlby-anonymous.email.view_post') }}</a></p>
