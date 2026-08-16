<?php

declare(strict_types=1);

namespace Phpvin\Http;

use RuntimeException;

/**
 * A file arriving on a request.
 *
 * Everything the browser tells us about an upload is attacker-controlled: the
 * filename, the extension, and the Content-Type. This class keeps those under
 * `client*` names so it is obvious when you are trusting them, and offers
 * detected alternatives (`mimeType()` sniffs the actual bytes, `store()`
 * writes a generated name), which are what you should use.
 */
final class UploadedFile
{
    /** Detected mime => canonical extension. Anything absent is not accepted. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
        'application/json' => 'json',
    ];

    private ?string $detectedMime = null;

    public function __construct(
        public readonly string $clientName,
        public readonly string $clientMimeType,
        public readonly int $size,
        public readonly string $temporaryPath,
        public readonly int $error = UPLOAD_ERR_OK,
    ) {}

    /**
     * @param array<string, mixed> $file One entry from $_FILES.
     */
    public static function fromArray(array $file): self
    {
        return new self(
            (string) ($file['name'] ?? ''),
            (string) ($file['type'] ?? ''),
            (int) ($file['size'] ?? 0),
            (string) ($file['tmp_name'] ?? ''),
            (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
        );
    }

    /**
     * Normalise a $_FILES entry, which PHP shapes differently for a single
     * input and for an array input like `photos[]`.
     *
     * @param  array<string, mixed> $entry
     * @return self|list<self>
     */
    public static function normalise(array $entry): self|array
    {
        if (! is_array($entry['name'] ?? null)) {
            return self::fromArray($entry);
        }

        $files = [];

        foreach (array_keys($entry['name']) as $index) {
            $files[] = self::fromArray([
                'name' => $entry['name'][$index] ?? '',
                'type' => $entry['type'][$index] ?? '',
                'size' => $entry['size'][$index] ?? 0,
                'tmp_name' => $entry['tmp_name'][$index] ?? '',
                'error' => $entry['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            ]);
        }

        return $files;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->temporaryPath !== '' && is_file($this->temporaryPath);
    }

    /**
     * Why the upload failed, in words a user can act on.
     */
    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => '',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder to write to.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
            default => 'The upload failed.',
        };
    }

    /**
     * The mime type read from the file's actual contents.
     *
     * Use this, not clientMimeType: a browser will happily label a PHP script
     * as image/png.
     */
    public function mimeType(): ?string
    {
        if ($this->detectedMime !== null) {
            return $this->detectedMime;
        }

        if (! $this->isValid() || ! function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $this->temporaryPath);
        finfo_close($finfo);

        return $this->detectedMime = ($mime === false ? null : $mime);
    }

    /**
     * The extension implied by the detected mime type, never the one the
     * client sent.
     */
    public function extension(): ?string
    {
        return self::EXTENSIONS[$this->mimeType()] ?? null;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mimeType(), 'image/');
    }

    public function sizeInKilobytes(): float
    {
        return $this->size / 1024;
    }

    /**
     * A collision-resistant name derived from random bytes plus the detected
     * extension. The client's filename never reaches the filesystem.
     */
    public function hashName(): string
    {
        $extension = $this->extension();

        return bin2hex(random_bytes(16)) . ($extension === null ? '' : '.' . $extension);
    }

    /**
     * Move the upload into $directory and return the path written.
     *
     * @param string|null $name Defaults to hashName(). A supplied name is
     *                          stripped of any directory component, so a
     *                          "../../" filename cannot escape the target.
     */
    public function store(string $directory, ?string $name = null): string
    {
        if (! $this->isValid()) {
            throw new RuntimeException('Cannot store an upload that did not arrive cleanly: ' . $this->errorMessage());
        }

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the upload directory [$directory].");
        }

        $name = $name === null ? $this->hashName() : basename($name);
        $target = rtrim($directory, '/') . '/' . $name;

        // move_uploaded_file refuses anything that did not arrive via POST,
        // which is the guard that matters in production. A hand-built instance
        // (a test, a queued job) falls through to rename.
        $moved = is_uploaded_file($this->temporaryPath)
            ? move_uploaded_file($this->temporaryPath, $target)
            : rename($this->temporaryPath, $target);

        if (! $moved) {
            throw new RuntimeException("Could not move the upload to [$target].");
        }

        return $target;
    }
}
