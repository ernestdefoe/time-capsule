<?php

namespace ErnestDefoe\TimeCapsule;

use Flarum\Foundation\Config;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Encryption at rest: XChaCha20-Poly1305 (libsodium), a random nonce per
 * letter and per photo, and the owner's id as associated data.
 *
 * The key never touches the database, so a look at the tables (or a database
 * dump) reads nothing. It comes from config.php (`'time-capsule' => ['key' =>
 * base64]`) when set there, else from `key` on the extension's private disk
 * (storage/time-capsule by default, registered in extend.php), written once
 * with random bytes the first time a letter is sealed. 🚨 Lose that file and
 * every sealed letter is lost with it: the admin page says to back it up.
 *
 * A key is only ever created while there are no letters. With letters in the
 * database and no key, the key was lost (a restore without storage/, a new
 * server): a fresh key would read none of them and quietly seal new ones
 * under it, so sealing stops (KeyMissing) and the log says why, loudly.
 * Reading never throws: a letter it can't decrypt shows as unreadable.
 */
class Vault
{
    public const DISK = 'ernestdefoe-time-capsule';
    private const PREFIX = 'tc1:';
    private const KEY_FILE = 'key';

    private ?string $key = null;
    /** Set once the key turned out to be lost: later reads in this request skip the check and the log line. */
    private ?KeyMissing $lost = null;

    public function __construct(
        private readonly Factory $filesystems,
        private readonly Config $config,
        private readonly Locks $locks,
        private readonly LoggerInterface $log,
    ) {
    }

    public function seal(string $plain, string $context): string
    {
        return self::PREFIX.base64_encode($this->sealBytes($plain, $context));
    }

    /** null when the text cannot be decrypted (wrong key, damaged row). */
    public function open(string $sealed, string $context): ?string
    {
        if (! str_starts_with($sealed, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);

        return $raw === false ? null : $this->openBytes($raw, $context);
    }

    public function sealBytes(string $plain, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $context, $nonce, $this->key());
    }

    public function openBytes(string $raw, string $context): ?string
    {
        $size = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($raw) <= $size) {
            return null;
        }
        try {
            $key = $this->key();
        } catch (KeyMissing|KeyBusy) {
            return null;
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $size), $context, substr($raw, 0, $size), $key);

        return $plain === false ? null : $plain;
    }

    /** The private disk that holds the key file and the encrypted photos. */
    public function disk(): Filesystem
    {
        return $this->filesystems->disk(self::DISK);
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $configured = $this->config['time-capsule.key'] ?? null;
        if (is_string($configured) && $configured !== '') {
            $key = base64_decode($configured, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new RuntimeException('config.php time-capsule.key must be 32 bytes, base64 encoded.');
            }

            return $this->key = $key;
        }

        return $this->key = $this->keyFromFile();
    }

    private function keyFromFile(): string
    {
        if ($this->lost) {
            throw $this->lost;
        }
        $disk = $this->disk();
        if (! $disk->exists(self::KEY_FILE)) {
            $this->createKey($disk);
        }

        $key = base64_decode(trim((string) $disk->get(self::KEY_FILE)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('Time Capsule cannot read its key file ('.self::KEY_FILE.' on the '.self::DISK.' disk).');
        }

        return $key;
    }

    private function createKey(Filesystem $disk): void
    {
        $this->assertNoLetters();

        // Two first letters sealed at the same moment must not write two different keys: one process
        // writes it under a lock, and the other is asked to try again (never made to wait in a request).
        $created = $this->locks->lock('key', 10)->get(function () use ($disk) {
            if (! $disk->exists(self::KEY_FILE)) {
                $this->assertNoLetters();
                if ($disk->put(self::KEY_FILE, base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen())) !== true) {
                    throw new RuntimeException('Time Capsule could not write its key file to the '.self::DISK.' disk.');
                }
            }

            return true;
        });

        if ($created !== true) {
            throw new KeyBusy('Time Capsule\'s key is being created by another request.');
        }
    }

    private function assertNoLetters(): void
    {
        if (Capsule::query()->exists()) {
            $this->log->critical('[time-capsule] The key file is missing from the '.self::DISK.' disk (storage/time-capsule/key), but there are sealed letters: they were sealed with that key and cannot be opened without it. Restore it from a backup. Sealing new letters is stopped until then.');

            throw $this->lost = new KeyMissing('Time Capsule\'s key is missing: restore storage/time-capsule/key from a backup.');
        }
    }
}
