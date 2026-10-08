{!! $translator->trans('adminlby-anonymous.email.body', ['{title}' => $blueprint->source->discussion->title]) !!}

{!! $url->to('forum')->route('discussion', ['id' => $blueprint->source->discussion_id]) !!}/{!! $blueprint->source->number !!}
