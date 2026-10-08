<?php

namespace AdminLby\Anonymous\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Post\Post;
use Flarum\User\User;

abstract class AnonymousBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(private AbstractModel $subject, public Post $source, private array $data)
    {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->subject;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): array
    {
        return $this->data + ['isAnonymous' => true];
    }

    public function getEmailViews(): array
    {
        return ['text' => 'adminlby-anonymous::emails.plain', 'html' => 'adminlby-anonymous::emails.html'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('adminlby-anonymous.email.subject', ['{title}' => $this->source->discussion->title]);
    }
}
