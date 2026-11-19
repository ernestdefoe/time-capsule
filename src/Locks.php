<?php

namespace ErnestDefoe\TimeCapsule;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use RuntimeException;

/**
 * Short locks from the forum's cache store (file, Redis, database: every store Flarum ships
 * provides them). Callers take them with get(), never block(): a request that finds one taken
 * answers "try again" instead of holding a PHP worker.
 */
class Locks
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function lock(string $name, int $seconds): Lock
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Time Capsule needs a cache store that supports locks.');
        }

        return $store->lock('time-capsule:'.$name, $seconds);
    }
}
