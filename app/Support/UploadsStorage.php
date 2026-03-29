<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadsStorage
{
    public static function diskName(): string
    {
        $disk = trim((string) config('filesystems.uploads_disk', 'public'));

        return $disk !== '' ? $disk : 'public';
    }

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(self::diskName());
    }

    public static function normalizePath(?string $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $trimmed = ltrim(trim($path), '/');

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'storage/')) {
            $trimmed = ltrim(substr($trimmed, strlen('storage/')), '/');
        }

        return $trimmed !== '' ? $trimmed : null;
    }

    public static function exists(?string $path): bool
    {
        $normalized = self::normalizePath($path);

        if ($normalized === null) {
            return false;
        }

        try {
            if (self::disk()->exists($normalized)) {
                return true;
            }
        } catch (Throwable) {
            // Fall back to legacy local/public lookup below.
        }

        foreach (self::publicPathCandidates($path, $normalized) as $candidate) {
            if (is_file(public_path($candidate))) {
                return true;
            }
        }

        return false;
    }

    public static function url(?string $path): ?string
    {
        $normalized = self::normalizePath($path);

        if ($normalized === null) {
            return null;
        }

        foreach (self::publicPathCandidates($path, $normalized) as $candidate) {
            if (is_file(public_path($candidate))) {
                return asset(self::encodePublicPath($candidate));
            }
        }

        try {
            return self::disk()->url($normalized);
        } catch (Throwable) {
            // Ignore and return null below.
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function files(string $directory): array
    {
        $normalizedDirectory = trim($directory, '/');

        if ($normalizedDirectory === '') {
            return [];
        }

        try {
            return self::disk()->files($normalizedDirectory);
        } catch (Throwable) {
            return [];
        }
    }

    public static function putFileAs(
        string $directory,
        UploadedFile $file,
        string $filename
    ): ?string {
        $normalizedDirectory = trim($directory, '/');

        if ($normalizedDirectory === '') {
            return null;
        }

        try {
            $storedPath = self::disk()->putFileAs($normalizedDirectory, $file, $filename);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($storedPath) || $storedPath === '') {
            return null;
        }

        return ltrim($storedPath, '/');
    }

    public static function delete(?string $path): void
    {
        $normalized = self::normalizePath($path);

        if ($normalized !== null) {
            try {
                self::disk()->delete($normalized);
            } catch (Throwable) {
                // Continue with local/public fallback cleanup.
            }
        }

        foreach (self::publicPathCandidates($path, $normalized) as $candidate) {
            $absolutePath = public_path($candidate);

            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function publicPathCandidates(?string $path, ?string $normalizedPath): array
    {
        $candidates = [];
        $raw = ltrim(trim((string) $path), '/');

        if ($raw !== '') {
            $candidates[] = $raw;
        }

        if ($normalizedPath !== null) {
            $candidates[] = $normalizedPath;
            $candidates[] = 'storage/' . $normalizedPath;
        }

        return array_values(array_unique(array_filter(
            $candidates,
            static fn (string $candidate): bool => trim($candidate) !== ''
        )));
    }

    private static function encodePublicPath(string $path): string
    {
        return collect(explode('/', $path))
            ->filter(static fn (string $segment): bool => $segment !== '')
            ->map(static fn (string $segment): string => rawurlencode($segment))
            ->implode('/');
    }
}
