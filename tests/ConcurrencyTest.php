<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\RateLimit\RateLimiter;

/**
 * Things that are only wrong when two requests arrive at once.
 *
 * A single-process suite runs each operation to completion before starting the
 * next, which is the one condition under which a read-modify-write race cannot
 * happen. So these tests spawn real processes: the interleaving has to be real
 * to be tested, and "it passed on my machine, serially" is exactly the
 * reassurance that lets this class of bug ship.
 */
final class ConcurrencyTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/phpvin-concurrent-' . bin2hex(random_bytes(6));

        mkdir($this->storage, 0o700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->storage));
    }

    #[Test]
    public function counting_attempts_does_not_lose_any_to_a_race(): void
    {
        // The limiter reads a count, adds one, and writes it back. If two
        // requests interleave between the read and the write, both see the
        // same number and both store the same increment, so one attempt is
        // silently forgotten. "Five tries a minute" quietly becomes "as many
        // as you can open at once" -- and an attacker is precisely the party
        // who sends requests in parallel on purpose.
        $workers = 4;
        $hitsEach = 15;

        $script = $this->storage . '/worker.php';
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        file_put_contents($script, <<<WORKER
            <?php
            require '{$autoload}';
            \$limiter = new \\Phpvin\\RateLimit\\RateLimiter('{$this->storage}');
            for (\$i = 0; \$i < {$hitsEach}; \$i++) {
                \$limiter->hit('login:ada', 300);
            }
            WORKER);

        $processes = [];

        for ($i = 0; $i < $workers; $i++) {
            $process = proc_open(
                [PHP_BINARY, $script],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            if ($process === false) {
                $this->markTestSkipped('Could not spawn a worker process.');
            }

            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                stream_get_contents($pipe);
                fclose($pipe);
            }

            proc_close($process);
        }

        $this->assertSame(
            $workers * $hitsEach,
            (new RateLimiter($this->storage))->attempts('login:ada'),
            'attempts were lost to a race between reading the count and writing it back',
        );
    }

    #[Test]
    public function a_window_that_expires_under_contention_still_restarts_once(): void
    {
        // Every worker finds an expired record at the same moment. Each must
        // start the new window and add exactly one, rather than each resetting
        // the count the others just wrote.
        $limiter = new RateLimiter($this->storage);
        $limiter->hit('burst', 1);

        // Force the record to be expired without waiting for a real second.
        $file = glob($this->storage . '/*.json')[0];
        $record = json_decode((string) file_get_contents($file), true);
        $record['expires'] = time() - 10;
        file_put_contents($file, json_encode($record));

        $script = $this->storage . '/expiry.php';
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        file_put_contents($script, <<<WORKER
            <?php
            require '{$autoload}';
            (new \\Phpvin\\RateLimit\\RateLimiter('{$this->storage}'))->hit('burst', 300);
            WORKER);

        $processes = [];

        for ($i = 0; $i < 4; $i++) {
            $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

            if ($process === false) {
                $this->markTestSkipped('Could not spawn a worker process.');
            }

            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                stream_get_contents($pipe);
                fclose($pipe);
            }

            proc_close($process);
        }

        // Four workers, one expired window: exactly four attempts in the new
        // one, not one and not eight.
        $this->assertSame(4, (new RateLimiter($this->storage))->attempts('burst'));
    }
}
