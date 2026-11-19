<?php

namespace ErnestDefoe\TimeCapsule\Notification;

use ErnestDefoe\TimeCapsule\Community;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * "The community time capsule '2027 hopes' is open": to every member (or
 * just its writers). The subject is the admin who started it.
 *
 * Sent in chunks of members by id (Opener): each chunk is its own blueprint,
 * marked by the id it starts after, so syncing one chunk never touches the
 * notifications of another, and a rerun of a chunk notifies nobody twice.
 */
class CommunityOpenedBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(public Community $community, public User $starter, public int $after = 0)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->starter;
    }

    public function getData(): mixed
    {
        return ['community' => $this->community->id, 'title' => $this->community->title, 'after' => $this->after];
    }

    public static function getType(): string
    {
        return 'timeCapsuleCommunityOpened';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ernestdefoe-time-capsule::emails.plain.community',
            'html' => 'ernestdefoe-time-capsule::emails.html.community',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-time-capsule.email.community.subject', ['{title}' => $this->community->title]);
    }
}
