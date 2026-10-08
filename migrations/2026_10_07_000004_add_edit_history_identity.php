<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema): void {
        if (! $schema->hasColumn('posts', 'last_edit_is_anonymous')) {
            $schema->table('posts', fn (Blueprint $table) => $table->boolean('last_edit_is_anonymous')->default(false));
        }
        // Keep our metadata separate from Diff's tables. The optional extension
        // can be disabled, installed later or reset without dropping this data.
        if (! $schema->hasTable('anonymous_edit_history')) {
            $schema->create('anonymous_edit_history', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('diff_id')->index();
                $table->unsignedInteger('post_id');
                $table->unsignedInteger('actor_reference')->nullable();
                $table->boolean('is_anonymous');
                $table->index(['post_id', 'is_anonymous']);
            });
        }
    },
    'down' => function (Builder $schema): void {
        $schema->dropIfExists('anonymous_edit_history');
        if ($schema->hasColumn('posts', 'last_edit_is_anonymous')) {
            $schema->table('posts', fn (Blueprint $table) => $table->dropColumn('last_edit_is_anonymous'));
        }
    },
];
