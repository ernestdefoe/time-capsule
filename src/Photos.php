<?php

namespace ErnestDefoe\TimeCapsule;

use Illuminate\Support\Collection;
use Intervention\Image\ImageManager;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Photos are re-encoded on the server (orientation applied, scaled down,
 * metadata such as GPS dropped by the re-encode), encrypted like the letter,
 * and kept under storage/, where no URL reaches them. PhotoController serves
 * one only to a viewer who may read its letter.
 */
class Photos
{
    private const MAX_SIDE = 1600;
    /** Checked from the header before decoding: a small file can declare a huge image and exhaust memory. */
    private const MAX_PIXELS = 50_000_000;

    public function __construct(private readonly Vault $vault, private readonly ImageManager $images)
    {
    }

    /**
     * @return array{file: string, width: int, height: int}|null null when it isn't an image we can read
     */
    public function store(UploadedFileInterface $upload, int $userId): ?array
    {
        if ($upload->getError() !== UPLOAD_ERR_OK || $upload->getSize() > Settings::MAX_PHOTO_BYTES) {
            return null;
        }

        $bytes = (string) $upload->getStream();
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        try {
            $image = $this->images->read($bytes);
            if ($image->isAnimated()) {
                $image->removeAnimation();
            }
            $image->scaleDown(self::MAX_SIDE, self::MAX_SIDE);
            $bytes = (string) $image->toWebp(82);
        } catch (Throwable) {
            return null;
        }

        $file = bin2hex(random_bytes(16)).'.bin';
        $dir = $this->directory();
        if (! is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        file_put_contents($dir.'/'.$file, $this->vault->sealBytes($bytes, self::context($userId)));

        return ['file' => $file, 'width' => $image->width(), 'height' => $image->height()];
    }

    /** The decrypted WebP, or null. */
    public function read(Photo $photo, int $userId): ?string
    {
        $path = $this->path($photo->file);
        if (! is_file($path)) {
            return null;
        }

        return $this->vault->openBytes((string) file_get_contents($path), self::context($userId));
    }

    /** @param iterable<string> $files */
    public function forget(iterable $files): void
    {
        foreach ($files as $file) {
            @unlink($this->path($file));
        }
    }

    /**
     * Deletes letters and their photo files. Rows go by their foreign keys too,
     * but the files only ever go here.
     *
     * @param Collection<int, int>|array<int> $capsuleIds
     */
    public function deleteCapsules(Collection|array $capsuleIds): void
    {
        $ids = collect($capsuleIds)->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            $this->forget(Photo::query()->whereIn('capsule_id', $chunk)->pluck('file'));
            Photo::query()->whereIn('capsule_id', $chunk)->delete();
            Capsule::query()->whereIn('id', $chunk)->delete();
        }
    }

    private function directory(): string
    {
        return $this->vault->directory().'/photos';
    }

    private function path(string $file): string
    {
        // Names are ours (hex + .bin); basename() keeps a damaged row from reaching outside.
        return $this->directory().'/'.basename($file);
    }

    private static function context(int $userId): string
    {
        return 'photo:'.$userId;
    }
}
