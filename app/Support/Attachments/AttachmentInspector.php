<?php

namespace App\Support\Attachments;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Decides whether an uploaded file may be stored, from its actual content: the
 * client's file name and MIME type are never trusted. Returns what the server
 * detected, or throws AttachmentRejected with a message safe to show the user.
 */
final class AttachmentInspector
{
    /** @return array{extension: string, mime_type: string, size: int, sha256: string} */
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new AttachmentRejected('The file could not be uploaded. Please try again.');
        }

        $path = $file->getRealPath();
        $size = $file->getSize();
        $config = config('helpdesk.attachments');

        if ($path === false || $size === false || $size === 0) {
            throw new AttachmentRejected('The file is empty.');
        }

        if ($size > $config['max_size_kb'] * 1024) {
            throw new AttachmentRejected('The file is larger than '.self::formatKb($config['max_size_kb']).'.');
        }

        // Only the extension of the name is looked at, and only against the allowlist.
        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $allowed = $config['types'][$extension] ?? null;

        if ($allowed === null) {
            throw new AttachmentRejected('This file type is not allowed. Allowed: '.implode(', ', array_keys($config['types'])).'.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        // libmagic cannot classify very short text files (e.g. "1"); judge those by their content instead.
        if ($mime === 'application/octet-stream' && in_array($extension, ['txt', 'csv'], true) && $this->looksLikeText($path)) {
            $mime = 'text/plain';
        }

        if (! in_array($mime, $allowed, true)) {
            throw new AttachmentRejected('The file content does not match its ".'.$extension.'" extension.');
        }

        match ($extension) {
            'jpg', 'jpeg', 'png', 'webp', 'gif' => $this->assertImage($path, $mime, (int) $config['max_image_pixels']),
            'pdf' => $this->assertPdf($path),
            'txt', 'csv' => $this->assertText($path),
            'docx' => $this->assertOffice($path, 'word/document.xml', (int) $config['max_unpacked_bytes']),
            'xlsx' => $this->assertOffice($path, 'xl/workbook.xml', (int) $config['max_unpacked_bytes']),
        };

        // A zip that passed the Office check is stored under its Office type, not "application/zip".
        $stored = match ($extension) {
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            default => $mime,
        };

        return [
            'extension' => $extension,
            'mime_type' => $stored,
            'size' => $size,
            'sha256' => hash_file('sha256', $path),
        ];
    }

    /**
     * A display name that is safe to show and to send as a download name: no path,
     * no control or direction-override characters, bounded length.
     */
    public static function cleanName(string $name, string $extension): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        // Control characters, and the Unicode bidi/zero-width characters used to disguise extensions.
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");

        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = trim((string) preg_replace('/[<>:"|?*]+/', '', $stem));
        if ($stem === '') {
            $stem = 'attachment';
        }
        $suffix = '.'.$extension;

        // Bound the length in characters, keeping the (verified) extension.
        return mb_substr($stem, 0, 120 - mb_strlen($suffix)).$suffix;
    }

    /** Valid UTF-8 with no control characters other than tab, CR and LF. */
    private function looksLikeText(string $path): bool
    {
        $content = (string) file_get_contents($path);

        return mb_check_encoding($content, 'UTF-8') && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content);
    }

    private function assertImage(string $path, string $mime, int $maxPixels): void
    {
        $info = @getimagesize($path);

        if ($info === false || ($info['mime'] ?? null) !== $mime) {
            throw new AttachmentRejected('The image is not valid.');
        }

        if ($info[0] * $info[1] > $maxPixels) {
            throw new AttachmentRejected('The image dimensions are too large.');
        }
    }

    private function assertPdf(string $path): void
    {
        $handle = fopen($path, 'rb');
        $head = $handle ? fread($handle, 5) : '';
        $handle && fclose($handle);

        if ($head !== '%PDF-') {
            throw new AttachmentRejected('The PDF is not valid.');
        }
    }

    private function assertText(string $path): void
    {
        $handle = fopen($path, 'rb');

        while ($handle && ! feof($handle)) {
            if (str_contains((string) fread($handle, 8192), "\0")) {
                fclose($handle);
                throw new AttachmentRejected('The text file contains binary data.');
            }
        }

        $handle && fclose($handle);
    }

    /** A real .docx/.xlsx (Office Open XML), without macros, that does not unpack to something enormous. */
    private function assertOffice(string $path, string $mainPart, int $maxUnpacked): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new AttachmentRejected('The document is not valid.');
        }

        $names = [];
        $unpacked = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');

            if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                $zip->close();
                throw new AttachmentRejected('The document is not valid.');
            }

            $names[] = strtolower($name);
            $unpacked += (int) ($stat['size'] ?? 0);
        }

        $zip->close();

        if (count($names) > 2000 || $unpacked > $maxUnpacked) {
            throw new AttachmentRejected('The document is too large when unpacked.');
        }

        if (! in_array('[content_types].xml', $names, true) || ! in_array($mainPart, $names, true)) {
            throw new AttachmentRejected('The document is not valid.');
        }

        foreach ($names as $name) {
            if (str_contains($name, 'vbaproject') || str_ends_with($name, '.bin') && str_contains($name, 'macro')) {
                throw new AttachmentRejected('Documents with macros are not allowed.');
            }
        }
    }

    private static function formatKb(int $kb): string
    {
        return $kb >= 1024 ? round($kb / 1024, 1).' MB' : $kb.' KB';
    }
}
