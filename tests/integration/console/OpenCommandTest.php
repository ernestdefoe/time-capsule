<?php

namespace ErnestDefoe\TimeCapsule\Tests\integration\console;

use Carbon\Carbon;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** The wiring: the command exists, the scheduler lists it, and it opens what's due exactly once. */
class OpenCommandTest extends ConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-time-capsule');

        $this->prepareDatabase([
            User::class => [['id' => 2, 'username' => 'normal', 'email' => 'normal@machine.local', 'is_email_confirmed' => 1]],
            'time_capsules' => [
                ['id' => 1, 'user_id' => 2, 'sealed_body' => 'tc1:x', 'opens_at' => Carbon::now()->subMinute(), 'sealed_at' => Carbon::now()->subYear(), 'visibility' => 'private'],
                ['id' => 2, 'user_id' => 2, 'sealed_body' => 'tc1:x', 'opens_at' => Carbon::now()->addYear(), 'sealed_at' => Carbon::now()->subYear(), 'visibility' => 'private'],
            ],
        ]);
    }

    #[Test]
    public function it_is_on_the_schedule()
    {
        $this->assertStringContainsString('time-capsule:open', $this->runCommand(['command' => 'schedule:list']));
    }

    #[Test]
    public function it_opens_what_is_due_once()
    {
        $this->assertStringContainsString('Opened 1', $this->runCommand(['command' => 'time-capsule:open']));
        $this->assertSame('', $this->runCommand(['command' => 'time-capsule:open']));
        $this->assertNotNull($this->database()->table('time_capsules')->where('id', 1)->value('opened_at'));
        $this->assertNull($this->database()->table('time_capsules')->where('id', 2)->value('opened_at'));
        $this->assertSame(1, $this->database()->table('notifications')->where('type', 'timeCapsuleOpened')->count());
    }
}
