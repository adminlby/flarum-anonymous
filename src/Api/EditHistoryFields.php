<?php

namespace AdminLby\Anonymous\Api;

use AdminLby\Anonymous\EditHistory\RevisionPrivacy;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use HuseyinFiliz\Diff\Models\Diff;

final class EditHistoryFields
{
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('isAnonymous')->get(fn (Diff $diff) => RevisionPrivacy::anonymous($diff)),
            Schema\Boolean::make('deletedByAnonymous')->get(fn (Diff $diff) => RevisionPrivacy::hidesAction($diff, 'deletedUser')),
            Schema\Boolean::make('rollbackByAnonymous')->get(fn (Diff $diff) => RevisionPrivacy::hidesAction($diff, 'rollbackedUser')),
            Schema\Boolean::make('isMine')->get(fn (Diff $diff, Context $context) => $context->getActor()->exists
                && RevisionPrivacy::anonymous($diff) && RevisionPrivacy::editorId($diff) === $context->getActor()->id),
            Schema\Boolean::make('canRevealAnonymousEditor')->get(fn (Diff $diff, Context $context) => $context->getActor()->exists
                && $context->getActor()->hasPermission('anonymous.reveal') && $context->getActor()->can('viewEditHistory') && RevisionPrivacy::anonymous($diff)),
        ];
    }
}
