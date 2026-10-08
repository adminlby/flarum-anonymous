<?php

namespace AdminLby\Anonymous\EditHistory;

use Flarum\Foundation\AbstractServiceProvider;
use HuseyinFiliz\Diff\Models\Diff;
use Illuminate\Contracts\Events\Dispatcher;

final class DiffServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(EditContext::class);
    }

    public function boot(): void
    {
        $record = $this->container->make(RecordRevisionIdentity::class);
        $this->container->make(Dispatcher::class)->listen('eloquent.created: '.Diff::class, fn (Diff $diff) => $record($diff));
    }
}
