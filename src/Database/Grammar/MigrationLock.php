<?php

declare(strict_types=1);

namespace Phpvin\Database\Grammar;

/**
 * The pair of statements that take and give back a migration lock.
 *
 * They travel together because neither means anything alone: taking a lock you
 * cannot release is worse than not taking one, since the deploy that follows
 * waits on a holder that will never let go. Pairing them also leaves one
 * question to ask (does this driver have advisory locks at all?) rather than
 * two that could disagree.
 */
final class MigrationLock
{
    /**
     * @param string $acquire A query returning a truthy first column when the
     *                        lock was taken, and a falsy one when it was not.
     * @param string $release A query giving the lock back.
     */
    public function __construct(
        public readonly string $acquire,
        public readonly string $release,
    ) {}
}
