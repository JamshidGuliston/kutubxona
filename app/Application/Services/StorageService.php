<?php

declare(strict_types=1);

namespace App\Application\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StorageService
{
    private readonly string $disk;
    private readonly string $bucket;

    public function __construct()
    {
        $this->disk   = config('filesystems.default', 's3');
        $this->bucket = config('filesystems.disks.s3.bucket', '');
    }

    /**
     * Upload a file to a temporary S3 location (awaiting processing).
     */
    public function uploadTemp(UploadedFile $file, string $prefix): string
    {
        $extension = $file->guessExtension() ?? 'bin';
        $filename  = Str::uuid() . '.' . $extension;
        $key       = ltrim($prefix, '/') . '/' . $filename;

        Storage::disk($this->disk)->put($key, file_get_contents($file->getRealPath()), [
            'visibility'         => 'private',
            'ServerSideEncryption' => 'AES256',
        ]);

        Log::debug('File uploaded to temp storage', ['key' => $key, 'size' => $file->getSize()]);

        return $key;
    }

    /**
     * Upload an image file (cover, avatar, etc.) with optimization.
     */
    public function uploadImage(UploadedFile $image, string $prefix): string
    {
        $extension = in_array($image->guessExtension(), ['jpg', 'jpeg', 'png', 'webp'])
            ? $image->guessExtension()
            : 'jpg';

        $filename = Str::uuid() . '.' . $extension;
        $key      = ltrim($prefix, '/') . '/' . $filename;

        Storage::disk($this->disk)->put($key, file_get_contents($image->getRealPath()), [
            'visibility'         => 'private',
            'ServerSideEncryption' => 'AES256',
            'ContentType'        => $image->getMimeType() ?? 'image/jpeg',
            'CacheControl'       => 'max-age=86400',
        ]);

        return $key;
    }

    /**
     * Upload processed file content (bytes) to final S3 location.
     */
    public function uploadContent(string $content, string $key, string $contentType = 'application/octet-stream'): string
    {
        Storage::disk($this->disk)->put($key, $content, [
            'visibility'         => 'private',
            'ServerSideEncryption' => 'AES256',
            'ContentType'        => $contentType,
        ]);

        return $key;
    }

    /**
     * Upload a local file path to S3.
     */
    public function uploadLocalFile(string $localPath, string $s3Key, string $contentType): string
    {
        $stream = fopen($localPath, 'rb');

        Storage::disk($this->disk)->put($s3Key, $stream, [
            'visibility'         => 'private',
            'ServerSideEncryption' => 'AES256',
            'ContentType'        => $contentType,
        ]);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $s3Key;
    }

    /**
     * Delete a file from S3.
     */
    public function delete(string $s3Key): bool
    {
        if (empty($s3Key)) return false;

        try {
            return Storage::disk($this->disk)->delete($s3Key);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete S3 file', ['key' => $s3Key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Check if a file exists in S3.
     */
    public function exists(string $s3Key): bool
    {
        return Storage::disk($this->disk)->exists($s3Key);
    }

    /**
     * Get file size in bytes.
     */
    public function getFileSize(string $s3Key): int
    {
        return Storage::disk($this->disk)->size($s3Key);
    }

    /**
     * Generate a presigned download URL (default 1 hour).
     */
    public function getSignedUrl(
        string $s3Key,
        int $expirySeconds = 3600,
        string $disposition = 'attachment',
        string $filename = ''
    ): string {
        $options = [
            'ResponseContentDisposition' => $filename
                ? "{$disposition}; filename=\"{$filename}\""
                : $disposition,
        ];

        return Storage::disk($this->disk)->temporaryUrl(
            $s3Key,
            now()->addSeconds($expirySeconds),
            $options
        );
    }

    /**
     * Generate a presigned streaming URL (shorter TTL, inline disposition).
     */
    public function getStreamingUrl(string $s3Key, int $expiryMinutes = 15): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $s3Key,
            now()->addMinutes($expiryMinutes),
            ['ResponseContentDisposition' => 'inline']
        );
    }

    /**
     * Move a file from one S3 key to another.
     */
    public function move(string $fromKey, string $toKey): bool
    {
        try {
            $content = Storage::disk($this->disk)->get($fromKey);
            if ($content === null) return false;

            Storage::disk($this->disk)->put($toKey, $content, [
                'visibility'         => 'private',
                'ServerSideEncryption' => 'AES256',
            ]);

            Storage::disk($this->disk)->delete($fromKey);
            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to move S3 file', [
                'from'  => $fromKey,
                'to'    => $toKey,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Generate the canonical S3 storage path for a resource.
     *
     * Pattern: tenants/{tenantId}/{type}/{resourceId}/{filename}
     */
    public function generateStoragePath(int $tenantId, string $type, string $filename): string
    {
        $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        return "tenants/{$tenantId}/{$type}/{$safeFilename}";
    }

    /**
     * Generate storage path for a book file.
     */
    public function generateBookFilePath(int $tenantId, int $bookId, string $filename): string
    {
        $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        return "tenants/{$tenantId}/books/{$bookId}/{$safeFilename}";
    }

    /**
     * Generate storage path for an audio chapter.
     */
    public function generateAudioPath(int $tenantId, int $audiobookId, string $filename): string
    {
        $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        return "tenants/{$tenantId}/audio/{$audiobookId}/{$safeFilename}";
    }

    /**
     * Setup tenant folder structure in S3 (create placeholder files).
     */
    public function setupTenantStorage(int $tenantId): void
    {
        $prefix    = "tenants/{$tenantId}";
        $subFolders = ['books', 'audio', 'images/authors', 'images/publishers', 'users', 'temp'];

        foreach ($subFolders as $folder) {
            $placeholderKey = "{$prefix}/{$folder}/.keep";
            if (!$this->exists($placeholderKey)) {
                Storage::disk($this->disk)->put($placeholderKey, '', ['visibility' => 'private']);
            }
        }

        Log::info('Tenant S3 storage structure initialized', ['tenant_id' => $tenantId]);
    }

    /**
     * Get all files under a tenant's S3 prefix (for storage audit).
     */
    public function listTenantFiles(int $tenantId): array
    {
        $prefix = "tenants/{$tenantId}/";
        return Storage::disk($this->disk)->allFiles($prefix);
    }

    /**
     * Calculate total storage used by a tenant.
     */
    public function calculateTenantStorageUsed(int $tenantId): int
    {
        $files   = $this->listTenantFiles($tenantId);
        $total   = 0;

        foreach ($files as $file) {
            $total += Storage::disk($this->disk)->size($file);
        }

        return $total;
    }
}
