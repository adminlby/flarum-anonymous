<?php

namespace AdminLby\Anonymous\EditHistory;

use Flarum\Database\AbstractModel;

final class RevisionIdentity extends AbstractModel
{
    protected $table = 'anonymous_edit_history';
    protected $fillable = ['diff_id', 'post_id', 'actor_reference', 'is_anonymous'];

    protected $casts = ['is_anonymous' => 'boolean', 'actor_reference' => 'integer'];
}
