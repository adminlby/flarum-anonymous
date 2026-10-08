<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema): void {
        $schema->table('posts', function (Blueprint $table): void {
            $table->boolean('is_anonymous')->default(false);
            $table->index(['user_id', 'is_anonymous'], 'anonymous_posts_author');
        });
        // A durable flag prevents disclosure if a first post is deleted.
        $schema->table('discussions', function (Blueprint $table): void {
            $table->boolean('is_anonymous')->default(false);
            $table->index(['user_id', 'is_anonymous'], 'anonymous_discussions_author');
        });
    },
    'down' => function (Builder $schema): void {
        $schema->table('posts', function (Blueprint $table): void {
            $table->dropIndex('anonymous_posts_author');
            $table->dropColumn('is_anonymous');
        });
        $schema->table('discussions', function (Blueprint $table): void {
            $table->dropIndex('anonymous_discussions_author');
            $table->dropColumn('is_anonymous');
        });
    },
];
