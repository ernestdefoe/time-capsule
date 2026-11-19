<?php

namespace ErnestDefoe\TimeCapsule\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\DiscussionTags;
use ErnestDefoe\TimeCapsule\Opener;
use ErnestDefoe\TimeCapsule\Tags\TaggedDiscussionTags;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** With flarum/tags enabled, opened letters' discussions go in the admin's tag (Extend\Conditional). */
class TagsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-time-capsule');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Tag::class => [['id' => 1, 'name' => 'Memories', 'slug' => 'memories', 'position' => 0, 'parent_id' => null]],
        ]);
    }

    private function sealAndOpen(): int
    {
        $response = $this->send($this->request('POST', '/api/time-capsule', ['authenticatedAs' => 2, 'json' => [
            'body' => 'A letter for the forum.',
            'opensAt' => Carbon::now()->addDays(3)->toIso8601String(),
            'visibility' => 'public',
            'post' => true,
        ]]));
        $this->assertSame(201, $response->getStatusCode());
        $id = json_decode((string) $response->getBody(), true)['capsule']['id'];
        $this->database()->table('time_capsules')->where('id', $id)->update(['opens_at' => Carbon::now()->subMinute()]);
        $this->app()->getContainer()->make(Opener::class)->run();

        return $id;
    }

    #[Test]
    public function the_tags_service_is_bound_only_with_tags_enabled()
    {
        $this->assertInstanceOf(TaggedDiscussionTags::class, $this->app()->getContainer()->make(DiscussionTags::class));
    }

    #[Test]
    public function a_letter_opens_into_the_admins_tag()
    {
        $this->setting('time-capsule.tag', '1');
        $this->sealAndOpen();

        $discussion = $this->database()->table('discussions')->first();
        $this->assertNotNull($discussion);
        $this->assertSame(1, (int) $this->database()->table('discussion_tag')->where('discussion_id', $discussion->id)->value('tag_id'));
    }

    #[Test]
    public function without_a_tag_a_member_gets_no_discussion_and_the_letter_still_opens()
    {
        $id = $this->sealAndOpen();

        $this->assertSame(0, $this->database()->table('discussions')->count());
        $this->assertNotNull($this->database()->table('time_capsules')->where('id', $id)->value('opened_at'));
    }
}
