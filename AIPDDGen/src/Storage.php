<?php

declare(strict_types=1);

namespace App;

/**
 * Local file storage for uploaded documents (template sources, organization
 * verification documents).
 *
 * Files live under storage/uploads/ with server-generated names (UUID + safe
 * extension) and are addressed by a relative key such as
 * `organization/2026/11/<uuid>.pdf`. Keys are never taken from user input, so a
 * path-traversal attempt cannot escape the upload directory.
 *
 * A production deployment can swap this class for S3-compatible storage behind
 * the same methods (put/read/path/delete).
 */
final class Storage
{
    /** Extensions accepted for template sources; the parser decides what it can read. */
    public const TEMPLATE_EXTENSIONS = ['pdf', 'docx', 'txt', 'md', 'vtt'];

    /** Extensions accepted for organization verification documents. */
    public const ORGANIZATION_DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx'];

    /** Product limit for one organization document (the effective limit may be lower; see maxUploadBytes()). */
    public const ORGANIZATION_DOCUMENT_MAX_BYTES = 50 * 1024 * 1024;

    private const DEFAULT_MAX_BYTES = 20 * 1024 * 1024;

    /** MIME types associated with each accepted extension (checked with finfo too). */
    private const MIME_BY_EXTENSION = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/cdfv2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'txt' => ['text/plain', 'text/*'],
        'md' => ['text/plain', 'text/markdown', 'text/x-markdown', 'text/*'],
        'vtt' => ['text/vtt', 'text/plain', 'text/*'],
    ];

    public static function root(): string
    {
        return self::normalizePath(APP_ROOT . '/' . Config::get('storage.uploads'));
    }

    /**
     * The largest file this PHP installation can actually accept: the lower of
     * the requested product limit and the relevant php.ini settings.
     */
    public static function maxUploadBytes(int $requested): int
    {
        $iniBytes = static fn (string $name): ?int => self::iniToBytes((string) ini_get($name));

        $uploadMax = $iniBytes('upload_max_filesize');
        $postMax = $iniBytes('post_max_size');

        $limit = $requested;
        foreach ([$uploadMax, $postMax] as $candidate) {
            if ($candidate !== null && $candidate > 0 && $candidate < $limit) {
                $limit = $candidate;
            }
        }

        return $limit;
    }

    /**
     * Validate and store one uploaded file ($_FILES entry).
     *
     * @param array<string, mixed> $file
     * @param list<string>|null $allowedExtensions
     * @return array{key: string, filename: string, mime_type: string, size_bytes: int, checksum_sha256: string, extension: string}
     */
    public static function putUploadedFile(
        array $file,
        string $directory = 'templates',
        ?array $allowedExtensions = null,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): array {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw AppError::validation(['file' => 'required']);
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw AppError::validation(['file' => 'too_large']);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw AppError::validation(['file' => 'upload_failed']);
        }

        $originalName = (string) ($file['name'] ?? '');
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if (!is_uploaded_file($tmpPath)) {
            throw AppError::validation(['file' => 'upload_failed']);
        }

        return self::store($tmpPath, $originalName, $size, $directory, $allowedExtensions, $maxBytes, true);
    }

    /**
     * Store a file that already exists on disk (imports, tests, migrations).
     * Same validation as an upload, but the file is moved rather than
     * re-uploaded, so it is not trusted as one of PHP's uploads.
     *
     * @param list<string>|null $allowedExtensions
     * @return array{key: string, filename: string, mime_type: string, size_bytes: int, checksum_sha256: string, extension: string}
     */
    public static function putLocalFile(
        string $sourcePath,
        string $originalName,
        string $directory = 'templates',
        ?array $allowedExtensions = null,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): array {
        if ($sourcePath === '' || !is_file($sourcePath)) {
            throw AppError::validation(['file' => 'upload_failed']);
        }

        return self::store($sourcePath, $originalName, (int) filesize($sourcePath), $directory, $allowedExtensions, $maxBytes, false);
    }

    /** Absolute path for a stored key; rejects anything that is not a safe relative key. */
    public static function path(string $key): string
    {
        if ($key === '' || !preg_match('#^[A-Za-z0-9][A-Za-z0-9/_.-]*$#', $key) || str_contains($key, '..')) {
            throw new \InvalidArgumentException('Invalid storage key');
        }

        $root = self::root();
        $absolute = self::normalizePath($root . '/' . ltrim($key, '/'));

        // Compare on a slash-normalised form: realpath() returns backslashes on
        // Windows while the key is always slash-separated.
        $prefix = rtrim($root, '/') . '/';
        if (!str_starts_with($absolute, $prefix)) {
            throw new \InvalidArgumentException('Storage key escapes the upload directory');
        }

        return $absolute;
    }

    /**
     * Resolve `.`/`..` segments and, on Windows, separator style, without
     * requiring the leaf to exist. realpath() only resolves existing paths, so
     * the deepest existing ancestor is resolved and the rest is appended.
     */
    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        $real = realpath($path);
        if ($real !== false) {
            return str_replace('\\', '/', $real);
        }

        $tail = [];
        $current = $path;
        while (!is_dir($current)) {
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            array_unshift($tail, basename($current));
            $current = $parent;
        }

        $base = realpath($current);
        $resolved = $base !== false ? str_replace('\\', '/', $base) : rtrim($current, '/');

        return $tail === [] ? $resolved : $resolved . '/' . implode('/', $tail);
    }

    public static function exists(string $key): bool
    {
        return $key !== '' && is_file(self::path($key));
    }

    public static function delete(string $key): void
    {
        if (self::exists($key)) {
            @unlink(self::path($key));
        }
    }

    public static function size(string $key): int
    {
        return self::exists($key) ? (int) filesize(self::path($key)) : 0;
    }

    /** Human-readable byte size for the UI ("1.4 MB", "40 MB"). */
    public static function formatSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            $megabytes = $bytes / (1024 * 1024);
            return number_format($megabytes, fmod($megabytes, 1.0) === 0.0 ? 0 : 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * @param list<string>|null $allowedExtensions
     * @return array{key: string, filename: string, mime_type: string, size_bytes: int, checksum_sha256: string, extension: string}
     */
    private static function store(
        string $sourcePath,
        string $originalName,
        int $size,
        string $directory,
        ?array $allowedExtensions,
        int $maxBytes,
        bool $isUpload,
    ): array {
        $allowed = $allowedExtensions ?? self::TEMPLATE_EXTENSIONS;
        $effectiveMax = self::maxUploadBytes($maxBytes);

        if ($size <= 0) {
            throw AppError::validation(['file' => 'empty']);
        }
        if ($size > $effectiveMax) {
            throw AppError::validation(['file' => 'too_large']);
        }

        $extension = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed, true)) {
            throw AppError::validation(['file' => 'invalid_type']);
        }

        $mimeType = self::detectMimeType($sourcePath);
        $expectedMimes = self::MIME_BY_EXTENSION[$extension] ?? [];
        if ($expectedMimes !== [] && !self::mimeMatches($mimeType, $expectedMimes)) {
            throw AppError::validation(['file' => 'invalid_type']);
        }

        $relativeDirectory = trim($directory, '/') . '/' . gmdate('Y/m');
        $key = $relativeDirectory . '/' . Uuid::v4() . '.' . $extension;
        $absolutePath = self::path($key);

        $parent = dirname($absolutePath);
        if (!is_dir($parent)) {
            mkdir($parent, 0775, true);
        }

        $moved = $isUpload
            ? move_uploaded_file($sourcePath, $absolutePath)
            : (@rename($sourcePath, $absolutePath) || (@copy($sourcePath, $absolutePath) && @unlink($sourcePath)));

        if ($moved !== true) {
            throw AppError::internal();
        }
        @chmod($absolutePath, 0664);

        return [
            'key' => $key,
            'filename' => mb_substr(basename($originalName), 0, 255),
            'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'size_bytes' => $size,
            'checksum_sha256' => hash_file('sha256', $absolutePath) ?: '',
            'extension' => $extension,
        ];
    }

    private static function iniToBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $unit = mb_strtolower(mb_substr($value, -1));
        $number = (int) $value;
        if ($number <= 0) {
            return null;
        }

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private static function detectMimeType(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime)) {
                    return $mime;
                }
            }
        }
        return 'application/octet-stream';
    }

    /** @param list<string> $expected */
    private static function mimeMatches(string $detected, array $expected): bool
    {
        foreach ($expected as $pattern) {
            if (str_ends_with($pattern, '/*')) {
                if (str_starts_with($detected, substr($pattern, 0, -1))) {
                    return true;
                }
                continue;
            }
            if ($detected === $pattern) {
                return true;
            }
        }
        return false;
    }
}
