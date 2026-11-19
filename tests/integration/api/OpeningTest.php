<?php

namespace ErnestDefoe\TimeCapsule\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Capsule;
use ErnestDefoe\TimeCapsule\Opener;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Opening runs every minute on the scheduler and from page loads: it must
 * open each letter once, notify once, post once, however often it runs.
 */
class OpeningTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const MARKER = 'OTTER-2291-not-yet';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-time-capsule');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'third', 'email' => 'third@machine.local', 'is_email_confirmed' => 1],
            ],
        ]);
    }

    private function json(string $method, string $path, ?int $as, array $json = []): array
    {
        $response = $this->send($this->request($method, $path, array_filter(['authenticatedAs' => $as, 'json' => $json ?: null])));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function seal(array $fields = [], int $as = 2): int
    {
        [$status, $body] = $this->json('POST', '/api/time-capsule', $as, $fields + [
            'body' => 'Note to self: '.self::MARKER,
            'opensAt' => Carbon::now()->addDays(3)->toIso8601String(),
            'visibility' => 'private',
        ]);
        $this->assertSame(201, $status);

        return $body['capsule']['id'];
    }

    /** Starts or changes the community capsule through its JSON:API resource. */
    private function community(string $method, ?int $id, ?int $as, array $attributes): array
    {
        $data = ['type' => 'time-capsule-communities', 'attributes' => $attributes];
        if ($id) {
            $data['id'] = (string) $id;
        }
        [$status, $body] = $this->json($method, '/api/time-capsule-communities'.($id ? '/'.$id : ''), $as, ['data' => $data]);

        return [$status, isset($body['data']['id']) ? ['community' => ['id' => (int) $body['data']['id']]] : $body];
    }

    private function due(int $id): void
    {
        $this->database()->table('time_capsules')->where('id', $id)->update(['opens_at' => Carbon::now()->subMinute()]);
    }

    private function opener(): Opener
    {
        return $this->app()->getContainer()->make(Opener::class);
    }

    private function notifications(string $type, ?int $user = null): int
    {
        $q = $this->database()->table('notifications')->where('type', $type);

        return $user ? $q->where('user_id', $user)->count() : $q->count();
    }

    #[Test]
    public function the_scheduler_opens_a_due_letter_once_and_notifies_once()
    {
        $due = $this->seal();
        $later = $this->seal();
        $this->due($due);

        $this->assertSame(1, $this->opener()->run());
        $opened = $this->database()->table('time_capsules')->where('id', $due)->value('opened_at');
        $this->assertNotNull($opened);
        $this->assertNull($this->database()->table('time_capsules')->where('id', $later)->value('opened_at'));

        // Again, and again: nothing new.
        $this->assertSame(0, $this->opener()->run());
        $this->assertSame(0, $this->opener()->run());
        $this->assertSame($opened, $this->database()->table('time_capsules')->where('id', $due)->value('opened_at'));
        $this->assertSame(1, $this->notifications('timeCapsuleOpened', 2));
        $this->assertSame(1, $this->notifications('timeCapsuleOpened'));

        // The alert carries no words of the letter.
        [, $alerts] = $this->json('GET', '/api/notifications', 2);
        $this->assertStringNotContainsString(self::MARKER, json_encode($alerts));
    }

    #[Test]
    public function two_openers_racing_for_one_letter_open_it_once()
    {
        $id = $this->seal();
        $this->due($id);

        $a = Capsule::query()->with('user')->find($id);
        $b = Capsule::query()->with('user')->find($id);

        $this->assertTrue($this->opener()->open($a));
        $this->assertFalse($this->opener()->open($b));
        $this->assertSame(1, $this->notifications('timeCapsuleOpened'));
    }

    #[Test]
    public function a_public_letter_with_post_starts_one_discussion()
    {
        $id = $this->seal(['visibility' => 'public', 'post' => true, 'envelope' => 'For the future']);
        $this->due($id);

        $this->opener()->run();
        $this->opener()->run();

        $this->assertSame(1, $this->database()->table('discussions')->count());
        $discussion = $this->database()->table('discussions')->first();
        $this->assertSame('For the future', $discussion->title);
        $this->assertSame(2, (int) $discussion->user_id);
        $this->assertStringContainsString(self::MARKER, (string) $this->database()->table('posts')->where('discussion_id', $discussion->id)->value('content'));
        $this->assertSame((int) $discussion->id, (int) $this->database()->table('time_capsules')->where('id', $id)->value('discussion_id'));
    }

    #[Test]
    public function one_letter_that_fails_does_not_stop_the_others_or_the_community()
    {
        // Notifying this one letter's writer throws (after Flarum has stored the alert).
        $this->extend((new \Flarum\Extend\Event())->listen(\Flarum\Notification\Event\Sent::class, function ($event) {
            if (($event->blueprint->capsule->envelope ?? null) === 'BOOM') {
                throw new \RuntimeException('mail server on fire');
            }
        }));

        [, $created] = $this->community('POST', null, 1, ['title' => 'All of us', 'opensAt' => Carbon::now()->addDays(30)->toIso8601String(), 'notifyEveryone' => false]);
        $this->seal(['community' => $created['community']['id']], 3);
        $bad = $this->seal(['envelope' => 'BOOM']);
        $good = $this->seal(['envelope' => 'fine']);
        $this->database()->table('time_capsules')->whereIn('id', [$bad, $good])->update(['opens_at' => Carbon::now()->subMinute()]);
        $this->database()->table('time_capsule_communities')->update(['opens_at' => Carbon::now()->subMinute()]);

        $this->opener()->run();

        $this->assertNotNull($this->database()->table('time_capsules')->where('id', $bad)->value('opened_at'));
        $this->assertNotNull($this->database()->table('time_capsules')->where('id', $good)->value('notified_at'));
        $this->assertSame(1, $this->notifications('timeCapsuleCommunityOpened', 3));

        // And the writer's page, which opens letters too, still loads.
        $this->assertSame(200, $this->json('GET', '/api/time-capsule', 2)[0]);
    }

    #[Test]
    public function a_letter_that_cannot_be_posted_is_not_retried_and_one_that_throws_is()
    {
        $id = $this->seal(['visibility' => 'public', 'post' => true]);
        $this->database()->table('time_capsules')->where('id', $id)->update(['opens_at' => Carbon::now()->subMinute(), 'sealed_body' => 'tc1:damaged']);

        $this->opener()->run();
        $row = $this->database()->table('time_capsules')->where('id', $id)->first();
        $this->assertNotNull($row->posted_at, 'an unreadable letter is marked done');
        $this->assertSame(0, (int) $row->post_attempts);
        $this->assertSame(0, $this->database()->table('discussions')->count());
    }

    #[Test]
    public function a_private_letter_never_starts_a_discussion()
    {
        $id = $this->seal(['visibility' => 'private', 'post' => true]);
        $this->due($id);
        $this->opener()->run();

        $this->assertSame(0, $this->database()->table('discussions')->count());
    }

    #[Test]
    public function a_community_capsule_keeps_every_letter_sealed_then_opens_them_together()
    {
        [$status, $created] = $this->community('POST', null, 1, [
            'title' => 'Hopes for next year',
            'opensAt' => Carbon::now()->addDays(30)->toIso8601String(),
            'notifyEveryone' => false,
            'post' => false,
        ]);
        $this->assertSame(201, $status);
        $community = $created['community']['id'];

        // Only one sealed at a time; members can't start one.
        $this->assertSame(422, $this->community('POST', null, 1, ['title' => 'Another', 'opensAt' => Carbon::now()->addDays(40)->toIso8601String()])[0]);
        $this->assertSame(403, $this->community('POST', null, 2, ['title' => 'Mine', 'opensAt' => Carbon::now()->addDays(40)->toIso8601String()])[0]);

        $first = $this->seal(['community' => $community], 2);
        $this->seal(['community' => $community], 3);

        foreach ([null, 1, 2, 3, 4] as $as) {
            [$status, $body] = $this->json('GET', '/api/time-capsule/community/'.$community, $as);
            $this->assertSame(200, $status);
            $this->assertStringNotContainsString(self::MARKER, json_encode($body));
            $this->assertSame(2, $body['community']['letters']);
            [, $page] = $this->json('GET', '/api/time-capsule', $as);
            $this->assertStringNotContainsString(self::MARKER, json_encode($page));
        }
        $this->assertSame(404, $this->json('GET', '/api/time-capsule/'.$first, 3)[0]);

        $this->database()->table('time_capsule_communities')->where('id', $community)->update(['opens_at' => Carbon::now()->subMinute()]);
        $this->opener()->run();
        $this->opener()->run();

        [, $body] = $this->json('GET', '/api/time-capsule/community/'.$community, 4);
        $this->assertCount(2, $body['letters']);
        $this->assertStringContainsString(self::MARKER, $body['letters'][0]['body']);
        $this->assertSame(200, $this->json('GET', '/api/time-capsule/'.$first, null)[0]);

        // The writers, once each; not the member who wrote nothing.
        $this->assertSame(1, $this->notifications('timeCapsuleCommunityOpened', 2));
        $this->assertSame(1, $this->notifications('timeCapsuleCommunityOpened', 3));
        $this->assertSame(0, $this->notifications('timeCapsuleCommunityOpened', 4));
        $this->assertSame(0, $this->notifications('timeCapsuleOpened'));
    }

    #[Test]
    public function a_community_capsule_with_letters_can_only_move_later()
    {
        $opens = Carbon::now()->addDays(30);
        [, $created] = $this->community('POST', null, 1, ['title' => 'Next year', 'opensAt' => $opens->toIso8601String()]);
        $id = $created['community']['id'];

        // No letters yet: any day.
        $this->assertSame(200, $this->community('PATCH', $id, 1, ['title' => 'Next year', 'opensAt' => Carbon::now()->addDays(20)->toIso8601String()])[0]);

        $letter = $this->seal(['community' => $id]);
        $this->assertSame(422, $this->community('PATCH', $id, 1, ['title' => 'Next year', 'opensAt' => Carbon::now()->addDays(2)->toIso8601String()])[0]);
        $this->assertSame(200, $this->community('PATCH', $id, 1, ['title' => 'Next year', 'opensAt' => Carbon::now()->addDays(40)->toIso8601String()])[0]);

        $this->opener()->run();
        $this->assertNull($this->database()->table('time_capsules')->where('id', $letter)->value('opened_at'));
        $this->assertGreaterThan(Carbon::now()->addDays(39), Carbon::parse($this->database()->table('time_capsules')->where('id', $letter)->value('opens_at')));
    }

    #[Test]
    public function a_community_capsule_can_notify_everyone_once()
    {
        [, $created] = $this->community('POST', null, 1, [
            'title' => 'Everyone',
            'opensAt' => Carbon::now()->addDays(30)->toIso8601String(),
            'notifyEveryone' => true,
        ]);
        $this->database()->table('time_capsule_communities')->where('id', $created['community']['id'])->update(['opens_at' => Carbon::now()->subMinute()]);

        $this->opener()->run();
        // A crash after a chunk went out: the cursor rewinds, and nobody hears twice.
        $this->database()->table('time_capsule_communities')->update(['notify_cursor' => 0, 'notified_at' => null]);
        $this->opener()->run();

        foreach ([1, 2, 3, 4] as $user) {
            $this->assertSame(1, $this->notifications('timeCapsuleCommunityOpened', $user), "user $user");
        }
    }

    #[Test]
    public function deleting_a_member_deletes_their_letters()
    {
        $this->seal();
        $this->assertSame(204, $this->json('DELETE', '/api/users/2', 1)[0]);
        $this->assertSame(0, $this->database()->table('time_capsules')->count());
    }

    #[Test]
    public function the_community_capsule_resource_checks_who_and_deletes_its_letters()
    {
        [, $created] = $this->community('POST', null, 1, ['title' => 'Going away', 'opensAt' => Carbon::now()->addDays(30)->toIso8601String()]);
        $id = $created['community']['id'];
        $this->seal(['community' => $id], 2);

        $this->assertSame(403, $this->community('PATCH', $id, 2, ['title' => 'Hijacked'])[0]);
        $this->assertSame(403, $this->json('DELETE', '/api/time-capsule-communities/'.$id, 2)[0]);
        $this->assertSame(422, $this->community('PATCH', $id, 1, ['opensAt' => Carbon::now()->subDay()->toIso8601String()])[0]);

        $this->assertSame(204, $this->json('DELETE', '/api/time-capsule-communities/'.$id, 1)[0]);
        $this->assertSame(0, $this->database()->table('time_capsules')->count());

        // Another admin starting one at this very moment holds the lock: refused, not doubled.
        $lock = $this->app()->getContainer()->make(\ErnestDefoe\TimeCapsule\Locks::class)->lock('community', 10);
        $this->assertTrue($lock->get());
        $this->assertSame(422, $this->community('POST', null, 1, ['title' => 'Racing', 'opensAt' => Carbon::now()->addDays(30)->toIso8601String()])[0]);
        $lock->release();
        $this->assertSame(0, $this->database()->table('time_capsule_communities')->count());

        // An opened one can't be changed.
        [, $created] = $this->community('POST', null, 1, ['title' => 'Opened', 'opensAt' => Carbon::now()->addDays(30)->toIso8601String()]);
        $this->database()->table('time_capsule_communities')->update(['opened_at' => Carbon::now()]);
        $this->assertSame(422, $this->community('PATCH', $created['community']['id'], 1, ['title' => 'Changed'])[0]);
    }

    #[Test]
    public function a_community_letter_left_sealed_opens_on_the_next_run()
    {
        [, $created] = $this->community('POST', null, 1, ['title' => 'Late', 'opensAt' => Carbon::now()->addDays(30)->toIso8601String(), 'notifyEveryone' => false]);
        $letter = $this->seal(['community' => $created['community']['id']]);
        // The capsule opened, but its letters didn't (a letter sealed in that moment, or a run that stopped).
        $this->database()->table('time_capsule_communities')->update(['opens_at' => Carbon::now()->subMinute(), 'opened_at' => Carbon::now()]);

        $this->opener()->run();

        $this->assertNotNull($this->database()->table('time_capsules')->where('id', $letter)->value('opened_at'));
    }

    #[Test]
    public function viewing_never_writes_and_the_page_asks_to_open_what_is_due()
    {
        $mine = $this->seal();
        $theirs = $this->seal([], 3);
        $this->due($mine);
        $this->due($theirs);

        $this->assertSame(200, $this->json('GET', '/api/time-capsule', 2)[0]);
        $this->assertSame(200, $this->json('GET', '/api/time-capsule/'.$mine, 2)[0]);
        $this->assertNull($this->database()->table('time_capsules')->where('id', $mine)->value('opened_at'));

        // The page's open request opens the viewer's own due letters, never anyone else's.
        [$status, $body] = $this->json('POST', '/api/time-capsule/open', 2);
        $this->assertSame(200, $status);
        $this->assertSame(1, $body['opened']);
        $this->assertNotNull($this->database()->table('time_capsules')->where('id', $mine)->value('opened_at'));
        $this->assertNull($this->database()->table('time_capsules')->where('id', $theirs)->value('opened_at'));
        $this->assertSame(1, $this->notifications('timeCapsuleOpened', 2));

        // Again: nothing more opens, nobody is notified twice.
        $this->assertSame(0, $this->json('POST', '/api/time-capsule/open', 2)[1]['opened']);
        $this->assertSame(1, $this->notifications('timeCapsuleOpened', 2));
    }

    #[Test]
    public function a_letters_discussion_shows_only_to_those_who_may_see_it()
    {
        $id = $this->seal(['visibility' => 'public', 'post' => true]);
        $this->due($id);
        $this->opener()->run();
        $discussion = (int) $this->database()->table('time_capsules')->where('id', $id)->value('discussion_id');
        $this->assertGreaterThan(0, $discussion);

        $this->assertSame($discussion, $this->json('GET', '/api/time-capsule/'.$id, null)[1]['capsule']['discussion']['id']);

        $this->database()->table('discussions')->where('id', $discussion)->update(['hidden_at' => Carbon::now()]);
        $this->assertNull($this->json('GET', '/api/time-capsule/'.$id, null)[1]['capsule']['discussion']);
        $this->assertNull($this->json('GET', '/api/time-capsule', null)[1]['recent'][0]['discussion']);
    }

    #[Test]
    public function your_opened_letters_come_a_page_at_a_time()
    {
        $now = Carbon::now();
        $rows = [];
        for ($i = 0; $i < 31; $i++) {
            $rows[] = ['user_id' => 2, 'sealed_body' => 'tc1:x', 'words' => 1, 'photo_count' => 0, 'opens_at' => $now, 'sealed_at' => $now, 'opened_at' => $now->copy()->subMinutes($i), 'notified_at' => $now, 'visibility' => 'private'];
        }
        $this->database()->table('time_capsules')->insert($rows);

        [, $page] = $this->json('GET', '/api/time-capsule', 2);
        $this->assertCount(30, $page['mine']);
        $this->assertTrue($page['moreMine']);

        $response = $this->send($this->request('GET', '/api/time-capsule/mine', ['authenticatedAs' => 2])->withQueryParams(['offset' => '30']));
        $this->assertSame(200, $response->getStatusCode());
        $more = json_decode((string) $response->getBody(), true);
        $this->assertCount(1, $more['letters']);
        $this->assertFalse($more['more']);
        $this->assertSame(401, $this->json('GET', '/api/time-capsule/mine', null)[0]);
    }

    #[Test]
    public function a_community_alert_skips_members_who_may_not_view_time_capsules()
    {
        $this->database()->table('group_permission')->where('permission', 'time-capsule.view')->delete();
        $this->database()->table('group_permission')->insert(['group_id' => 4, 'permission' => 'time-capsule.view']);
        $this->database()->table('group_user')->insert(['user_id' => 3, 'group_id' => 4]);

        [, $created] = $this->community('POST', null, 1, ['title' => 'Staff only', 'opensAt' => Carbon::now()->addDays(30)->toIso8601String(), 'notifyEveryone' => true]);
        $this->database()->table('time_capsule_communities')->where('id', $created['community']['id'])->update(['opens_at' => Carbon::now()->subMinute()]);
        $this->opener()->run();

        $this->assertSame(1, $this->notifications('timeCapsuleCommunityOpened', 1), 'admin');
        $this->assertSame(1, $this->notifications('timeCapsuleCommunityOpened', 3), 'moderator');
        $this->assertSame(0, $this->notifications('timeCapsuleCommunityOpened', 2), 'member without the permission');
    }
}

