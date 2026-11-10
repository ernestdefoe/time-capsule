<?php

namespace ErnestDefoe\TimeCapsule\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\TimeCapsule\Vault;
use Flarum\Foundation\Paths;
use Flarum\Gdpr\Jobs\ExportJob;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * flarum/gdpr lets a moderator export someone else's data and keeps the file
 * for that moderator. Letter text goes into an export only when members
 * export themselves.
 */
class GdprExportTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const MARKER = 'HERON-4410-private-words';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-gdpr', 'ernestdefoe-time-capsule');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
        ]);
    }

    private function letters(): void
    {
        $vault = $this->app()->getContainer()->make(Vault::class);
        $now = Carbon::now();
        // Every row with the same columns: PostgreSQL won't take a multi-row insert that differs.
        $row = fn (array $o) => $o + ['user_id' => 2, 'sealed_body' => $vault->seal('Dear me, '.self::MARKER, 'capsule:2'), 'sealed_at' => $now->copy()->subYear(), 'visibility' => 'private', 'words' => 3, 'author_can_read' => false, 'opened_at' => null];
        $this->database()->table('time_capsules')->insert([
            $row(['opens_at' => $now->copy()->addYear(), 'author_can_read' => true]),
            $row(['opens_at' => $now->copy()->subDay(), 'opened_at' => $now->copy()->subDay()]),
        ]);
    }

    /** Runs flarum/gdpr's own export job and returns our files from the zip it wrote. */
    private function export(int $actor): string
    {
        $container = $this->app()->getContainer();
        $job = new ExportJob(User::findOrFail(2), User::findOrFail($actor));
        $container->call([$job, 'handle']);

        $zips = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($container->make(Paths::class)->storage, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.zip')) {
                $zips[$file->getMTime().$file->getPathname()] = $file->getPathname();
            }
        }
        $this->assertNotEmpty($zips, 'no export written');
        krsort($zips);
        $zip = new ZipArchive();
        $zip->open(reset($zips));
        $ours = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with($zip->getNameIndex($i), 'time-capsule/')) {
                $ours .= $zip->getFromIndex($i);
            }
        }
        $zip->close();
        array_map('unlink', $zips);

        return $ours;
    }

    #[Test]
    public function a_moderators_export_of_a_member_has_no_letter_text()
    {
        $this->letters();
        $ours = $this->export(1);

        $this->assertStringContainsString('opens_at', $ours, 'the cards are there');
        $this->assertStringNotContainsString(self::MARKER, $ours);
    }

    #[Test]
    public function a_members_own_export_has_the_letters_they_can_read()
    {
        $this->letters();
        $this->assertSame(2, substr_count($this->export(2), self::MARKER));
    }
}
