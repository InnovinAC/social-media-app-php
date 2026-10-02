<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Log\FileLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

final class LoggerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/phpvin-log-' . bin2hex(random_bytes(6)) . '/app.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        if (is_dir(dirname($this->path))) {
            rmdir(dirname($this->path));
        }
    }

    private function contents(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }

    #[Test]
    public function it_is_a_psr_logger(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, new FileLogger($this->path));
    }

    #[Test]
    public function it_writes_a_timestamped_line(): void
    {
        (new FileLogger($this->path))->error('Something broke');

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\s+ERROR\s+Something broke$/m',
            trim($this->contents()),
        );
    }

    #[Test]
    public function it_creates_the_directory_it_needs(): void
    {
        (new FileLogger($this->path))->info('first line');

        $this->assertFileExists($this->path);
    }

    #[Test]
    public function lines_accumulate(): void
    {
        $logger = new FileLogger($this->path);
        $logger->info('one');
        $logger->info('two');

        $this->assertCount(2, array_filter(explode("\n", trim($this->contents()))));
    }

    #[Test]
    public function it_fills_placeholders_from_the_context(): void
    {
        (new FileLogger($this->path))->error('Template {name} failed', ['name' => 'errors/500']);

        $this->assertStringContainsString('Template errors/500 failed', $this->contents());
    }

    #[Test]
    public function a_consumed_placeholder_is_not_repeated_in_the_tail(): void
    {
        (new FileLogger($this->path))->error('Route {path} failed', ['path' => '/notes', 'status' => 500]);

        $line = $this->contents();

        $this->assertStringContainsString('Route /notes failed', $line);
        $this->assertStringNotContainsString('"path"', $line);
        $this->assertStringContainsString('"status":500', $line);
    }

    #[Test]
    public function leftover_context_is_appended_as_json(): void
    {
        (new FileLogger($this->path))->warning('Odd', ['user' => 7, 'ip' => '127.0.0.1']);

        $this->assertStringContainsString('{"user":7,"ip":"127.0.0.1"}', $this->contents());
    }

    #[Test]
    public function a_throwable_in_the_context_is_flattened_rather_than_dumped(): void
    {
        (new FileLogger($this->path))->error('Failed', ['exception' => new RuntimeException('the cause')]);

        $line = $this->contents();

        $this->assertStringContainsString('"class":"RuntimeException"', $line);
        $this->assertStringContainsString('"message":"the cause"', $line);
        $this->assertStringContainsString('LoggerTest.php:', $line);
    }

    #[Test]
    public function levels_below_the_threshold_are_dropped(): void
    {
        $logger = new FileLogger($this->path, LogLevel::WARNING);

        $logger->debug('noise');
        $logger->info('more noise');
        $logger->warning('worth keeping');
        $logger->error('definitely worth keeping');

        $contents = $this->contents();

        $this->assertStringNotContainsString('noise', $contents);
        $this->assertStringContainsString('worth keeping', $contents);
        $this->assertCount(2, array_filter(explode("\n", trim($contents))));
    }

    #[Test]
    public function an_unknown_threshold_is_rejected_at_construction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown log level');

        new FileLogger($this->path, 'chatty');
    }

    #[Test]
    public function every_psr_level_is_accepted(): void
    {
        $logger = new FileLogger($this->path);

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            $logger->{$level}("at $level");
        }

        $this->assertCount(8, array_filter(explode("\n", trim($this->contents()))));
    }

    #[Test]
    public function an_unwritable_path_does_not_take_the_request_down(): void
    {
        // Losing a log line is bad; turning a handled error into a fatal is worse.
        $logger = new FileLogger('/proc/nope/cannot-write.log');

        $logger->error('this has nowhere to go');

        $this->addToAssertionCount(1);
    }
}
