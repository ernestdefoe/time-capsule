<?php

namespace ErnestDefoe\TimeCapsule\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * The seal has to hold: a sealed letter's words and photos are never in any
 * answer the forum gives, to anyone, through any endpoint or include, and
 * never plain text in the database.
 */
class SealingTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const MARKER = 'ZEBRA-7731-quietly-hidden';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-time-capsule');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
            ],
        ]);
    }

    /** @return array{int, array<string, mixed>|null, string} */
    private function send_(string $method, string $path, ?int $as = 2, array $json = [], array $files = []): array
    {
        $request = $this->request($method, $path, array_filter(['authenticatedAs' => $as, 'json' => $json ?: null]));
        if ($files) {
            $request = $request->withUploadedFiles(['photos' => $files])->withParsedBody($json);
        }
        $response = $this->send($request);
        $body = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($body, true), $body];
    }

    private function seal(array $fields = [], ?int $as = 2, array $files = []): array
    {
        return $this->send_('POST', '/api/time-capsule', $as, $fields + [
            'body' => 'Dear future me, '.self::MARKER.'. Hello from the past.',
            'envelope' => 'For later',
            'opensAt' => Carbon::now()->addYears(5)->toIso8601String(),
            'visibility' => 'public',
        ], $files);
    }

    private function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tc').'.png';
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 40, 40));
        imagepng($image, $path);

        return new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'photo.png', 'image/png');
    }

    /** Every place a letter or its writer could surface, as each kind of viewer. */
    private function assertNowhere(int $id, string $marker = self::MARKER): void
    {
        $paths = [
            '/api/time-capsule',
            '/api/time-capsule/'.$id,
            '/api/users/2',
            '/api/users/2?include=groups',
            '/api/forum',
            '/api/notifications',
            '/api/discussions',
        ];

        foreach ([null, 1, 2, 3] as $as) {
            foreach ($paths as $path) {
                [, , $raw] = $this->send_('GET', $path, $as);
                $this->assertStringNotContainsString($marker, $raw, "$path as ".($as ?? 'guest'));
                $this->assertStringNotContainsString('tc1:', $raw, "ciphertext in $path");
            }
        }
    }

    #[Test]
    public function the_database_holds_ciphertext_only()
    {
        [$status, $body, $raw] = $this->seal();

        $this->assertSame(201, $status, $raw);
        $this->assertTrue($body['capsule']['sealed']);
        $this->assertArrayNotHasKey('body', $body['capsule']);
        $this->assertStringNotContainsString(self::MARKER, $raw);

        $row = $this->database()->table('time_capsules')->first();
        $this->assertStringStartsWith('tc1:', $row->sealed_body);
        $this->assertStringNotContainsString(self::MARKER, $row->sealed_body);
        $this->assertStringNotContainsString(self::MARKER, base64_decode(substr($row->sealed_body, 4)));
        $this->assertSame(8, (int) $row->words);
    }

    #[Test]
    public function a_sealed_letter_is_in_no_response_for_anyone()
    {
        [, $body] = $this->seal();
        $this->assertNowhere($body['capsule']['id']);

        // The writer sees the card, never the words.
        [$status, $show] = $this->send_('GET', '/api/time-capsule/'.$body['capsule']['id'], 2);
        $this->assertSame(200, $status);
        $this->assertTrue($show['capsule']['sealed']);
        $this->assertArrayNotHasKey('body', $show['capsule']);
        $this->assertArrayNotHasKey('photos', $show['capsule']);

        // Everyone else: as if it didn't exist. Admins included.
        foreach ([null, 1, 3] as $as) {
            $this->assertSame(404, $this->send_('GET', '/api/time-capsule/'.$body['capsule']['id'], $as)[0]);
        }
    }

    #[Test]
    public function a_writer_who_kept_a_key_can_reread_it_and_nobody_else_can()
    {
        [, $body] = $this->seal(['peek' => true]);
        $id = $body['capsule']['id'];

        [$status, $show] = $this->send_('GET', '/api/time-capsule/'.$id, 2);
        $this->assertSame(200, $status);
        $this->assertStringContainsString(self::MARKER, $show['capsule']['body']);
        $this->assertFalse($show['capsule']['firstRead']);

        foreach ([null, 1, 3] as $as) {
            $this->assertSame(404, $this->send_('GET', '/api/time-capsule/'.$id, $as)[0]);
            [, , $raw] = $this->send_('GET', '/api/time-capsule', $as);
            $this->assertStringNotContainsString(self::MARKER, $raw);
        }
    }

    #[Test]
    public function a_sealed_letters_photos_answer_404_and_are_encrypted_on_disk()
    {
        [$status, $body, $raw] = $this->seal([], 2, [$this->photo()]);
        $this->assertSame(201, $status, $raw);
        $this->assertSame(1, $body['capsule']['photoCount']);
        $this->assertArrayNotHasKey('photos', $body['capsule']);

        $photo = $this->database()->table('time_capsule_photos')->first();
        $id = $body['capsule']['id'];
        foreach ([null, 1, 2, 3] as $as) {
            $this->assertSame(404, $this->send_('GET', "/api/time-capsule/$id/photos/{$photo->id}", $as)[0]);
        }

        $file = $this->app()->getContainer()->make(\Flarum\Foundation\Paths::class)->storage.'/time-capsule/photos/'.$photo->file;
        $this->assertFileExists($file);
        $this->assertStringNotContainsString('WEBP', (string) file_get_contents($file));
        $this->assertStringNotContainsString('PNG', (string) file_get_contents($file));
    }

    #[Test]
    public function an_open_private_letter_is_the_writers_alone()
    {
        [, $body] = $this->seal(['visibility' => 'private', 'opensAt' => Carbon::now()->addDays(2)->toIso8601String()]);
        $id = $body['capsule']['id'];
        $this->database()->table('time_capsules')->where('id', $id)->update(['opens_at' => Carbon::now()->subMinute()]);

        // The writer's page opens it (no scheduler run yet).
        [, $page] = $this->send_('GET', '/api/time-capsule', 2);
        $this->assertFalse($page['mine'][0]['sealed']);
        $this->assertStringContainsString(self::MARKER, $page['mine'][0]['body']);

        foreach ([null, 1, 3] as $as) {
            $this->assertSame(404, $this->send_('GET', '/api/time-capsule/'.$id, $as)[0]);
            [, , $raw] = $this->send_('GET', '/api/time-capsule', $as);
            $this->assertStringNotContainsString(self::MARKER, $raw);
        }
    }

    #[Test]
    public function an_open_public_letter_is_readable_by_everyone_who_may_view()
    {
        [, $body] = $this->seal([], 2, [$this->photo()]);
        $id = $body['capsule']['id'];
        $this->database()->table('time_capsules')->where('id', $id)->update(['opens_at' => Carbon::now()->subMinute()]);
        $this->app()->getContainer()->make(\ErnestDefoe\TimeCapsule\Opener::class)->run();

        foreach ([null, 3] as $as) {
            [$status, $show] = $this->send_('GET', '/api/time-capsule/'.$id, $as);
            $this->assertSame(200, $status);
            $this->assertStringContainsString(self::MARKER, $show['capsule']['body']);
            $this->assertCount(1, $show['capsule']['photos']);
            [, $page] = $this->send_('GET', '/api/time-capsule', $as);
            $this->assertSame($id, $page['recent'][0]['id']);
        }

        $photo = $this->database()->table('time_capsule_photos')->first();
        $response = $this->send($this->request('GET', "/api/time-capsule/$id/photos/{$photo->id}"));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/webp', $response->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('private', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('RIFF', substr((string) $response->getBody(), 0, 4));
    }

    #[Test]
    public function the_writer_reads_it_new_once()
    {
        [, $body] = $this->seal(['opensAt' => Carbon::now()->addDays(2)->toIso8601String()]);
        $id = $body['capsule']['id'];
        $this->database()->table('time_capsules')->where('id', $id)->update(['opens_at' => Carbon::now()->subMinute()]);

        $this->assertTrue($this->send_('GET', '/api/time-capsule/'.$id, 2)[1]['capsule']['firstRead']);
        $this->assertFalse($this->send_('GET', '/api/time-capsule/'.$id, 2)[1]['capsule']['firstRead']);
    }

    #[Test]
    public function the_rules_for_sealing()
    {
        // Guests are stopped (CSRF first, then the login check).
        $this->assertContains($this->seal([], null)[0], [400, 401]);
        $this->assertSame(0, $this->database()->table('time_capsules')->count());
        $this->assertSame(422, $this->seal(['body' => '   '])[0]);
        $this->assertSame(422, $this->seal(['body' => str_repeat('a', 20001)])[0]);
        $this->assertSame(422, $this->seal(['opensAt' => Carbon::now()->addHours(2)->toIso8601String()])[0]);
        $this->assertSame(422, $this->seal(['opensAt' => Carbon::now()->addYears(26)->toIso8601String()])[0]);
        $this->assertSame(422, $this->seal(['opensAt' => 'next tuesday'])[0]);
        $this->assertSame(422, $this->seal(['envelope' => str_repeat('e', 81)])[0]);
        $this->assertSame(422, $this->seal([], 2, array_fill(0, 5, $this->photo()))[0]);
        $this->assertSame(201, $this->seal(['opensAt' => Carbon::now()->addYears(25)->toIso8601String()])[0]);
        $this->assertSame(0, $this->database()->table('time_capsule_photos')->count());
    }

    #[Test]
    public function a_lost_key_is_never_silently_replaced()
    {
        [, $body] = $this->seal(['opensAt' => Carbon::now()->addDays(2)->toIso8601String()]);
        $this->database()->table('time_capsules')->update(['opens_at' => Carbon::now()->subMinute()]);
        $key = $this->app()->getContainer()->make(\Flarum\Foundation\Paths::class)->storage.'/time-capsule/key';
        $this->assertFileExists($key);
        unlink($key);

        [$status, $error] = $this->seal();
        $this->assertSame(503, $status);
        $this->assertSame('time_capsule_key_missing', $error['errors'][0]['code']);
        $this->assertFileDoesNotExist($key);
        $this->assertSame(1, $this->database()->table('time_capsules')->count());

        // Reading still works: the letter shows as unreadable, nothing 500s.
        [$status, $show] = $this->send_('GET', '/api/time-capsule/'.$body['capsule']['id'], 2);
        $this->assertSame(200, $status);
        $this->assertFalse($show['capsule']['readable']);
        $this->assertSame(200, $this->send_('GET', '/api/time-capsule', 2)[0]);
    }

    #[Test]
    public function an_image_too_big_to_decode_is_refused_before_decoding()
    {
        // A valid PNG header declaring 20000 x 20000 pixels, a few bytes long.
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 20000, 20000)."\x08\x02\x00\x00\x00";
        $png .= pack('N', crc32(substr($png, 12, 17)));
        $path = tempnam(sys_get_temp_dir(), 'tc');
        file_put_contents($path, $png);

        [$status] = $this->seal([], 2, [new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'bomb.png', 'image/png')]);
        $this->assertSame(422, $status);
        $this->assertSame(0, $this->database()->table('time_capsules')->count());
    }

    #[Test]
    public function photos_over_16_megapixels_are_refused_from_the_header()
    {
        $header = function (int $w, int $h): UploadedFile {
            $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', $w, $h)."\x08\x02\x00\x00\x00";
            $png .= pack('N', crc32(substr($png, 12, 17)));
            $path = tempnam(sys_get_temp_dir(), 'tc');
            file_put_contents($path, $png);

            return new UploadedFile($path, filesize($path), UPLOAD_ERR_OK, 'p.png', 'image/png');
        };
        $photos = $this->app()->getContainer()->make(\ErnestDefoe\TimeCapsule\Photos::class);

        $this->assertFalse($photos->fits($header(4500, 4000)), '18 MP');
        $this->assertTrue($photos->fits($header(4000, 4000)), '16 MP');
        $this->assertTrue($photos->fits($this->photo()));

        // Every photo is checked before any is stored: nothing is left behind.
        $dir = $this->app()->getContainer()->make(\Flarum\Foundation\Paths::class)->storage.'/time-capsule/photos';
        $count = fn () => is_dir($dir) ? count(array_diff(scandir($dir), ['.', '..'])) : 0;
        $before = $count();
        $this->assertSame(422, $this->seal([], 2, [$this->photo(), $header(4500, 4000)])[0]);
        $this->assertSame($before, $count());
    }

    #[Test]
    public function only_the_writer_or_a_moderator_deletes_and_photos_go_too()
    {
        [, $body] = $this->seal([], 2, [$this->photo()]);
        $id = $body['capsule']['id'];
        $photo = $this->database()->table('time_capsule_photos')->first();
        $file = $this->app()->getContainer()->make(\Flarum\Foundation\Paths::class)->storage.'/time-capsule/photos/'.$photo->file;

        $this->assertSame(404, $this->send_('DELETE', '/api/time-capsule/'.$id, 3)[0]);
        $this->assertSame(204, $this->send_('DELETE', '/api/time-capsule/'.$id, 2)[0]);
        $this->assertSame(0, $this->database()->table('time_capsules')->count());
        $this->assertSame(0, $this->database()->table('time_capsule_photos')->count());
        $this->assertFileDoesNotExist($file);

        [, $body] = $this->seal();
        $this->assertSame(204, $this->send_('DELETE', '/api/time-capsule/'.$body['capsule']['id'], 1)[0]);
    }
}
