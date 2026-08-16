<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Phpvin\Http\Request;
use Phpvin\Http\UploadedFile;
use Phpvin\Validation\Validator;
use RuntimeException;

final class UploadTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/phpvin-uploads-' . bin2hex(random_bytes(6));
        mkdir($this->work, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->work . '/*') ?: [] as $file) {
            is_dir($file) ? array_map('unlink', glob("$file/*") ?: []) && rmdir($file) : unlink($file);
        }

        if (is_dir($this->work)) {
            rmdir($this->work);
        }
    }

    /** A genuine 1x1 PNG, so finfo has real bytes to read. */
    private function png(string $name = 'photo.png'): UploadedFile
    {
        $path = $this->work . '/' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ));

        return new UploadedFile($name, 'image/png', (int) filesize($path), $path);
    }

    /** A PHP script wearing a PNG name and Content-Type. */
    private function disguisedScript(): UploadedFile
    {
        $path = $this->work . '/' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($path, "<?php system(\$_GET['c']); ");

        return new UploadedFile('innocent.png', 'image/png', (int) filesize($path), $path);
    }

    // --- detection --------------------------------------------------------

    #[Test]
    public function it_reads_the_mime_type_from_the_bytes(): void
    {
        $this->assertSame('image/png', $this->png()->mimeType());
    }

    #[Test]
    public function the_clients_content_type_is_not_believed(): void
    {
        $file = $this->disguisedScript();

        $this->assertSame('image/png', $file->clientMimeType, 'what the browser claimed');
        $this->assertNotSame('image/png', $file->mimeType(), 'what the bytes say');
        $this->assertFalse($file->isImage());
    }

    #[Test]
    public function the_extension_comes_from_the_detected_type(): void
    {
        $this->assertSame('png', $this->png('anything.jpg')->extension());
    }

    #[Test]
    public function an_unrecognised_type_has_no_extension(): void
    {
        $this->assertNull($this->disguisedScript()->extension());
    }

    // --- storing ----------------------------------------------------------

    #[Test]
    public function storing_generates_a_name_and_never_uses_the_clients(): void
    {
        $file = $this->png('../../etc/passwd');
        $target = $this->work . '/store';

        $written = $file->store($target);

        $this->assertFileExists($written);
        $this->assertSame($target, dirname($written));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', basename($written));
    }

    #[Test]
    public function a_supplied_name_cannot_escape_the_target_directory(): void
    {
        $target = $this->work . '/store';

        $written = $this->png()->store($target, '../escaped.png');

        // basename() strips the traversal rather than trusting it.
        $this->assertSame($target . '/escaped.png', $written);
    }

    #[Test]
    public function storing_a_failed_upload_is_refused(): void
    {
        $broken = new UploadedFile('x.png', 'image/png', 0, '', UPLOAD_ERR_INI_SIZE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('larger than the server allows');

        $broken->store($this->work);
    }

    #[Test]
    public function upload_errors_are_explained_in_words(): void
    {
        $file = new UploadedFile('x', '', 0, '', UPLOAD_ERR_PARTIAL);

        $this->assertFalse($file->isValid());
        $this->assertStringContainsString('partially uploaded', $file->errorMessage());
    }

    // --- reading off the request ------------------------------------------

    #[Test]
    public function a_single_file_field_is_read_from_the_request(): void
    {
        $path = $this->work . '/one.txt';
        file_put_contents($path, 'hello');

        $request = new Request(
            method: 'POST',
            path: '/upload',
            files: ['doc' => ['name' => 'a.txt', 'type' => 'text/plain', 'size' => 5, 'tmp_name' => $path, 'error' => 0]],
        );

        $this->assertInstanceOf(UploadedFile::class, $request->file('doc'));
        $this->assertSame('a.txt', $request->file('doc')->clientName);
        $this->assertTrue($request->hasFile('doc'));
        $this->assertNull($request->file('missing'));
    }

    #[Test]
    public function an_array_file_field_is_flattened_into_a_list(): void
    {
        $paths = [];

        foreach (['a', 'b'] as $n) {
            $paths[] = $p = $this->work . "/$n.txt";
            file_put_contents($p, $n);
        }

        $request = new Request(
            method: 'POST',
            path: '/upload',
            files: ['photos' => [
                'name' => ['a.txt', 'b.txt'],
                'type' => ['text/plain', 'text/plain'],
                'size' => [1, 1],
                'tmp_name' => $paths,
                'error' => [0, 0],
            ]],
        );

        $files = $request->fileList('photos');

        $this->assertCount(2, $files);
        $this->assertSame('b.txt', $files[1]->clientName);
    }

    // --- validation -------------------------------------------------------

    #[Test]
    public function the_image_rule_rejects_a_disguised_script(): void
    {
        $errors = (new Validator())->check(['avatar' => $this->disguisedScript()], ['avatar' => 'required|image']);

        $this->assertSame(['Avatar must be an image.'], $errors['avatar']);
    }

    #[Test]
    public function the_image_rule_accepts_a_real_image(): void
    {
        $this->assertSame([], (new Validator())->check(['avatar' => $this->png()], ['avatar' => 'required|image']));
    }

    #[Test]
    public function the_mimes_rule_matches_on_the_detected_type(): void
    {
        $validator = new Validator();
        $file = $this->png();

        $this->assertSame([], $validator->check(['avatar' => $file], ['avatar' => 'mimes:png,jpg']));
        $this->assertArrayHasKey('avatar', $validator->check(['avatar' => $file], ['avatar' => 'mimes:pdf']));
    }

    #[Test]
    public function the_file_rule_reports_why_an_upload_failed(): void
    {
        $broken = new UploadedFile('x.png', 'image/png', 0, '', UPLOAD_ERR_CANT_WRITE);

        $errors = (new Validator())->check(['doc' => $broken], ['doc' => 'file']);

        $this->assertStringContainsString('could not write', $errors['doc'][0]);
    }

    #[Test]
    public function max_measures_an_upload_in_kilobytes(): void
    {
        $file = $this->png();

        $this->assertSame([], (new Validator())->check(['avatar' => $file], ['avatar' => 'max:100']));

        $errors = (new Validator())->check(['avatar' => $file], ['avatar' => 'max:0']);
        $this->assertStringContainsString('kB', $errors['avatar'][0]);
    }

    #[Test]
    public function a_missing_optional_file_passes(): void
    {
        $absent = new UploadedFile('', '', 0, '', UPLOAD_ERR_NO_FILE);

        $this->assertSame([], (new Validator())->check(['avatar' => $absent], ['avatar' => 'nullable|image']));
    }

    #[Test]
    public function a_missing_required_file_fails(): void
    {
        $absent = new UploadedFile('', '', 0, '', UPLOAD_ERR_NO_FILE);

        $this->assertArrayHasKey('avatar', (new Validator())->check(['avatar' => $absent], ['avatar' => 'required']));
    }
}
