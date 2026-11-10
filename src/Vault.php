<?php

namespace ErnestDefoe\TimeCapsule;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Encryption at rest: XChaCha20-Poly1305 (libsodium), a random nonce per
 * letter and per photo, and the owner's id as associated data.
 *
 * The key never touches the database, so a look at the tables (or a database
 * dump) reads nothing. It comes from config.php (`'time-capsule' => ['key' =>
 * base64]`) when set there, else from storage/time-capsule/key, written once
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
    private const PREFIX = 'tc1:';

    private ?string $key = null;

    public function __construct(
        private readonly Paths $paths,
        private readonly Config $config,
        private readonly ConnectionInterface $db,
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
        } catch (KeyMissing) {
            return null;
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $size), $context, substr($raw, 0, $size), $key);

        return $plain === false ? null : $plain;
    }

    public function directory(): string
    {
        return $this->paths->storage.'/time-capsule';
    }

    public function keyFile(): string
    {
        return $this->directory().'/key';
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
        $file = $this->keyFile();
        if (! is_file($file)) {
            $this->createKey($file);
        }

        $key = base64_decode(trim((string) @file_get_contents($file)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('Time Capsule cannot read its key at '.$file.'.');
        }

        return $key;
    }

    private function createKey(string $file): void
    {
        if ($this->db->table('time_capsules')->exists()) {
            $this->log->critical('[time-capsule] The key at '.$file.' is missing, but there are sealed letters: they were sealed with that key and cannot be opened without it. Restore storage/time-capsule/key from a backup. Sealing new letters is stopped until then.');

            throw new KeyMissing('Time Capsule\'s key is missing: restore storage/time-capsule/key from a backup.');
        }

        if (! is_dir($this->directory())) {
            @mkdir($this->directory(), 0770, true);
        }

        // Written whole to a temporary file, then linked into place: link() fails if another
        // process got there first (then we read theirs), and nobody ever reads a half-written key.
        $tmp = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
        file_put_contents($tmp, base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen()));
        @chmod($tmp, 0600);
        @link($tmp, $file);
        @unlink($tmp);
    }
}
