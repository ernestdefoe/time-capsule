<?php

namespace ErnestDefoe\TimeCapsule\Notification;

use ErnestDefoe\TimeCapsule\Capsule;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "The letter you wrote on 10 November 2026 has opened": to its writer. The
 * subject is the writer (a User has an API resource; a subject without one
 * 500s every notification list); the letter's id rides in the data, which
 * also makes the blueprint one per letter, so a second sync is a no-op. Not
 * a word of the letter goes in the alert or the email.
 */
class CapsuleOpenedBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(public Capsule $capsule)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->capsule->user;
    }

    public function getData(): mixed
    {
        return ['capsule' => $this->capsule->id, 'sealed' => $this->capsule->sealed_at->toIso8601String()];
    }

    public static function getType(): string
    {
        return 'timeCapsuleOpened';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-time-capsule::emails.plain.opened',
            'html' => 'ernestdefoe-time-capsule::emails.html.opened',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-time-capsule.email.opened.subject', [
            '{date}' => $this->capsule->sealed_at->isoFormat('LL'),
        ]);
    }
}
