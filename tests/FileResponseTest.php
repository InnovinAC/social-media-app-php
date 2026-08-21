<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Http\FileResponse;
use Phpvin\Http\StreamedResponse;
use RuntimeException;

final class FileResponseTest extends TestCase
{
    private string $directory;

    private string $file;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/phpvin-files-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o755, true);

        $this->file = $this->directory . '/report.csv';
        file_put_contents($this->file, "id,total\n1,10\n2,20\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    #[Test]
    public function a_download_prompts_a_save_dialog(): void
    {
        $response = FileResponse::download($this->file);

        $this->assertSame(200, $response->status());
        $this->assertStringStartsWith('attachment;', (string) $response->getHeader('Content-Disposition'));
        $this->assertStringContainsString('filename="report.csv"', (string) $response->getHeader('Content-Disposition'));
    }

    #[Test]
    public function an_inline_response_asks_the_browser_to_show_it(): void
    {
        $this->assertStringStartsWith(
            'inline;',
            (string) FileResponse::inline($this->file)->getHeader('Content-Disposition'),
        );
    }

    #[Test]
    public function the_length_and_type_come_from_the_file(): void
    {
        $response = FileResponse::download($this->file);

        $this->assertSame((string) filesize($this->file), $response->getHeader('Content-Length'));
        $this->assertStringContainsString('text/', (string) $response->getHeader('Content-Type'));
    }

    #[Test]
    public function the_type_can_be_stated_outright(): void
    {
        $this->assertSame(
            'application/x-custom',
            FileResponse::download($this->file, contentType: 'application/x-custom')->getHeader('Content-Type'),
        );
    }

    #[Test]
    public function a_missing_file_is_refused_before_any_headers_are_set(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no readable file');

        FileResponse::download($this->directory . '/not-here.csv');
    }

    // --- the filename ---------------------------------------------------------

    #[Test]
    public function a_name_with_a_comma_or_a_space_survives(): void
    {
        $disposition = FileResponse::disposition('attachment', 'Q3 report, final.csv');

        // Unquoted, a comma ends the header value and the name is truncated.
        $this->assertStringContainsString('filename="Q3 report, final.csv"', $disposition);
    }

    #[Test]
    public function a_non_ascii_name_is_sent_both_ways(): void
    {
        $disposition = FileResponse::disposition('attachment', 'résumé.pdf');

        // An old client gets a readable ASCII fallback; everything since 2010
        // gets the real name.
        $this->assertStringContainsString('filename="r_sum_.pdf"', $disposition);
        $this->assertStringNotContainsString('r__sum__', $disposition, 'one character, one underscore');
        $this->assertStringContainsString("filename*=UTF-8''r%C3%A9sum%C3%A9.pdf", $disposition);
    }

    #[Test]
    public function a_name_cannot_inject_another_header(): void
    {
        $disposition = FileResponse::disposition('attachment', "report\ncsv\r\nSet-Cookie: admin=1");

        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString('Set-Cookie', explode(';', $disposition)[0]);
    }

    #[Test]
    public function a_name_cannot_escape_the_quoted_string(): void
    {
        $disposition = FileResponse::disposition('attachment', 'evil".csv');

        // One opening and one closing quote around the filename, no more.
        $this->assertSame(2, substr_count(explode('filename*', $disposition)[0], '"'));
    }

    #[Test]
    public function a_name_that_is_not_valid_utf8_still_produces_a_header(): void
    {
        $disposition = FileResponse::disposition('attachment', "broken\xFF\xFEname.csv");

        $this->assertStringContainsString('filename="', $disposition);
        $this->assertStringNotContainsString("\xFF", $disposition);
    }

    #[Test]
    public function control_characters_become_underscores(): void
    {
        $this->assertStringContainsString(
            'filename="__"',
            FileResponse::disposition('attachment', "\x01\x02"),
        );
    }

    #[Test]
    public function a_name_that_strips_to_nothing_still_gets_one(): void
    {
        // Only quotes and backslashes, all of which are removed outright;
        // a header reading `filename=""` helps nobody.
        $this->assertStringContainsString(
            'filename="download"',
            FileResponse::disposition('attachment', '"\\"'),
        );
    }

    #[Test]
    public function a_directory_component_is_stripped_from_the_name(): void
    {
        $this->assertStringContainsString(
            'filename="passwd"',
            FileResponse::disposition('attachment', '../../etc/passwd'),
        );
    }

    #[Test]
    public function the_name_defaults_to_the_file_on_disk(): void
    {
        $this->assertStringContainsString(
            'filename="report.csv"',
            (string) FileResponse::download($this->file)->getHeader('Content-Disposition'),
        );
    }

    #[Test]
    public function a_different_name_can_be_offered_to_the_client(): void
    {
        $this->assertStringContainsString(
            'filename="Your Report.csv"',
            (string) FileResponse::download($this->file, 'Your Report.csv')->getHeader('Content-Disposition'),
        );
    }

    #[Test]
    public function the_body_is_the_file(): void
    {
        ob_start();
        FileResponse::download($this->file)->stream();
        $streamed = (string) ob_get_clean();

        $this->assertSame(file_get_contents($this->file), $streamed);
    }

    #[Test]
    public function a_file_larger_than_one_chunk_arrives_whole(): void
    {
        // The read loop runs more than once, which is where an off-by-one in
        // chunking would show up.
        $big = $this->directory . '/big.bin';
        $contents = random_bytes(8192 * 3 + 17);
        file_put_contents($big, $contents);

        ob_start();
        FileResponse::download($big)->stream();
        $streamed = (string) ob_get_clean();

        $this->assertSame(strlen($contents), strlen($streamed));
        $this->assertSame($contents, $streamed);
    }

    #[Test]
    public function an_empty_file_streams_as_nothing(): void
    {
        $empty = $this->directory . '/empty.txt';
        touch($empty);

        ob_start();
        FileResponse::download($empty)->stream();

        $this->assertSame('', (string) ob_get_clean());
    }

    #[Test]
    public function a_directory_is_not_a_file(): void
    {
        $this->expectException(RuntimeException::class);

        FileResponse::download($this->directory);
    }

    #[Test]
    public function an_unreadable_file_is_refused(): void
    {
        $locked = $this->directory . '/locked.csv';
        file_put_contents($locked, 'secret');
        chmod($locked, 0o000);

        try {
            if (is_readable($locked)) {
                $this->markTestSkipped('Running as a user that can read anything.');
            }

            $this->expectException(RuntimeException::class);

            FileResponse::download($locked);
        } finally {
            chmod($locked, 0o644);
        }
    }

    #[Test]
    public function the_path_is_available_for_inspection(): void
    {
        $this->assertSame($this->file, FileResponse::download($this->file)->path());
    }

    // --- streaming --------------------------------------------------------------

    #[Test]
    public function a_streamed_response_produces_its_body_when_run(): void
    {
        $response = StreamedResponse::make(function (): void {
            $out = fopen('php://output', 'w');

            foreach ([[1, 10], [2, 20]] as $row) {
                fputcsv($out, $row, escape: '\\');
            }
        }, headers: ['Content-Type' => 'text/csv']);

        $this->assertSame("1,10\n2,20\n", $response->capture());
        $this->assertSame('text/csv', $response->getHeader('Content-Type'));
    }

    #[Test]
    public function a_streamed_response_carries_no_body_until_it_runs(): void
    {
        // The point of streaming: nothing is held in memory beforehand.
        $this->assertSame('', StreamedResponse::make(fn () => print('later'))->body());
    }

    #[Test]
    public function stream_writes_straight_to_output(): void
    {
        ob_start();
        StreamedResponse::make(fn () => print('written directly'))->stream();

        $this->assertSame('written directly', (string) ob_get_clean());
    }

    #[Test]
    public function a_streamed_response_can_set_a_status(): void
    {
        $this->assertSame(202, StreamedResponse::make(fn () => null, 202)->status());
    }

    #[Test]
    public function a_callback_that_throws_does_not_leave_a_buffer_open(): void
    {
        $before = ob_get_level();

        try {
            StreamedResponse::make(function (): void {
                echo 'partial output';

                throw new RuntimeException('halfway');
            })->capture();

            $this->fail('Expected the callback to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('halfway', $e->getMessage());
        }

        $this->assertSame($before, ob_get_level());
    }
}
