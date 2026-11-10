<?php

namespace ErnestDefoe\TimeCapsule;

use Flarum\Foundation\Config;
use Flarum\Foundation\Paths;
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
 */
class Vault
{
    private const PREFIX = 'tc1:';

    private ?string $key = null;

    public function __construct(private readonly Paths $paths, private readonly Config $config)
    {
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
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $size), $context, substr($raw, 0, $size), $this->key());

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
            if (! is_dir($this->directory())) {
                @mkdir($this->directory(), 0770, true);
            }
            // 'x' fails if another process wrote the key first: then we read theirs.
            $handle = @fopen($file, 'x');
            if ($handle !== false) {
                fwrite($handle, base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen()));
                fclose($handle);
                @chmod($file, 0600);
            }
        }

        $key = base64_decode(trim((string) @file_get_contents($file)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('Time Capsule cannot read its key at '.$file.'.');
        }

        return $key;
    }
}
