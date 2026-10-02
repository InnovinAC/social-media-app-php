<?php

declare(strict_types=1);

/**
 * Refuse to run while a mutation run is rewriting the source.
 *
 * `bin/mutate` applies a mutant, runs the suite, and takes it back off,
 * hundreds of times. For the length of that run `src/` on disk is deliberately
 * not what git says it is, so any other tool reading the tree is reading a lie
 * and will report it as a finding. Both of these happened while this
 * framework was being built: a concurrent fuzz run reported a TypeError that
 * did not exist, and a packaging check packaged a mutated source and failed.
 *
 * Neither was a real defect, and both cost more time than the run they
 * overlapped. Guessing wrong about that is expensive enough, and easy enough,
 * that the tools say so rather than leaving it to whoever remembers.
 */
function refuseWhileMutating(string $tool): void
{
    $lockPath = sys_get_temp_dir() . '/phpvin-mutate.lock';

    if (! file_exists($lockPath)) {
        return;
    }

    $pid = (int) @file_get_contents($lockPath);

    // Signal 0 tests for the process without touching it. A stale lock from a
    // killed run is not a reason to refuse.
    if ($pid <= 0 || ! function_exists('posix_kill') || ! posix_kill($pid, 0)) {
        return;
    }

    fwrite(STDERR, sprintf(
        "\033[31m%s cannot run while bin/mutate is going (pid %d).\033[0m\n"
        . "It rewrites src/ hundreds of times, so anything read now is a mutant\n"
        . "rather than the source, and any finding would be about that.\n",
        $tool,
        $pid,
    ));

    exit(3);
}
