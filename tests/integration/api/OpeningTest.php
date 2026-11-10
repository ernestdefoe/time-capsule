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

    private const SECRET = 'OTTER-2291-not-yet';

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
            'body' => 'Note to self: '.self::SECRET,
            'opensAt' => Carbon::now()->addDays(3)->toIso8601String(),
            'visibility' => 'private',
        ]);
        $this->assertSame(201, $status);

        return $body['capsule']['id'];
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
        $this->assertStringNotContainsString(self::SECRET, json_encode($alerts));
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
        $this->assertStringContainsString(self::SECRET, (string) $this->database()->table('posts')->where('discussion_id', $discussion->id)->value('content'));
        $this->assertSame((int) $discussion->id, (int) $this->database()->table('time_capsules')->where('id', $id)->value('discussion_id'));
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
        [$status, $created] = $this->json('POST', '/api/time-capsule/community', 1, [
            'title' => 'Hopes for next year',
            'opensAt' => Carbon::now()->addDays(30)->toIso8601String(),
            'notifyEveryone' => false,
            'post' => false,
        ]);
        $this->assertSame(201, $status);
        $community = $created['community']['id'];

        // Only one sealed at a time; members can't start one.
        $this->assertSame(422, $this->json('POST', '/api/time-capsule/community', 1, ['title' => 'Another', 'opensAt' => Carbon::now()->addDays(40)->toIso8601String()])[0]);
        $this->assertSame(403, $this->json('POST', '/api/time-capsule/community', 2, ['title' => 'Mine', 'opensAt' => Carbon::now()->addDays(40)->toIso8601String()])[0]);

        $first = $this->seal(['community' => $community], 2);
        $this->seal(['community' => $community], 3);

        foreach ([null, 1, 2, 3, 4] as $as) {
            [$status, $body] = $this->json('GET', '/api/time-capsule/community/'.$community, $as);
            $this->assertSame(200, $status);
            $this->assertStringNotContainsString(self::SECRET, json_encode($body));
            $this->assertSame(2, $body['community']['letters']);
            [, $page] = $this->json('GET', '/api/time-capsule', $as);
            $this->assertStringNotContainsString(self::SECRET, json_encode($page));
        }
        $this->assertSame(404, $this->json('GET', '/api/time-capsule/'.$first, 3)[0]);

        $this->database()->table('time_capsule_communities')->where('id', $community)->update(['opens_at' => Carbon::now()->subMinute()]);
        $this->opener()->run();
        $this->opener()->run();

        [, $body] = $this->json('GET', '/api/time-capsule/community/'.$community, 4);
        $this->assertCount(2, $body['letters']);
        $this->assertStringContainsString(self::SECRET, $body['letters'][0]['body']);
        $this->assertSame(200, $this->json('GET', '/api/time-capsule/'.$first, null)[0]);

        // The writers, once each; not the member who wrote nothing.
        $this->assertSame(1, $this->notifications('timeCapsuleCommunityOpened', 2));
        $this->assertSame(1, $this->notifications('timeCapsuleCommunityOpened', 3));
        $this->assertSame(0, $this->notifications('timeCapsuleCommunityOpened', 4));
        $this->assertSame(0, $this->notifications('timeCapsuleOpened'));
    }

    #[Test]
    public function a_community_capsule_can_notify_everyone_once()
    {
        [, $created] = $this->json('POST', '/api/time-capsule/community', 1, [
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
}
