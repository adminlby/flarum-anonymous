<?php

use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (Builder $schema): void {
        $schema->table('posts', fn (Blueprint $table) => $table->unsignedInteger('anonymous_author_id')->nullable());
        // Core sets posts.user_id to NULL when an account is deleted. Keep an
        // internal immutable numeric reference for moderation in that case.
        $schema->getConnection()->table('posts')->where('is_anonymous', true)
            ->update(['anonymous_author_id' => $schema->getConnection()->raw('user_id')]);
    },
    'down' => function (Builder $schema): void {
        $schema->table('posts', fn (Blueprint $table) => $table->dropColumn('anonymous_author_id'));
    },
];
