<?php

declare(strict_types=1);

namespace App\Domain\Book\Models;

use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $book_id
 * @property string $file_type
 * @property string $s3_key
 * @property string $s3_bucket
 * @property string $original_name
 * @property int $file_size
 * @property string $mime_type
 * @property string|null $checksum_md5
 * @property string|null $checksum_sha256
 * @property bool $is_primary
 * @property string $processing_status
 * @property string|null $processing_error
 * @property array|null $metadata
 * @property int|null $uploaded_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class BookFile extends Model
{
    protected $table = 'book_files';

    protected $fillable = [
        'tenant_id',
        'book_id',
        'file_type',
        's3_key',
        's3_bucket',
        'original_name',
        'file_size',
        'mime_type',
        'checksum_md5',
        'checksum_sha256',
        'is_primary',
        'processing_status',
        'processing_error',
        'metadata',
        'uploaded_by',
    ];

    protected $casts = [
        'is_primary'  => 'boolean',
        'file_size'   => 'integer',
        'metadata'    => 'array',
    ];

    protected $hidden = [
        's3_key',
        's3_bucket',
        'checksum_md5',
        'checksum_sha256',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $file): void {
            if (app()->has('tenant') && empty($file->tenant_id)) {
                $file->tenant_id = app('tenant')->id;
            }
        });
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    // ─── Accessors ───────────────────────────────────────────────────────────────

    protected function fileSizeMb(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round($this->file_size / 1024 / 1024, 2)
        );
    }

    protected function fileSizeHuman(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $bytes = $this->file_size;
                if ($bytes < 1024) return "{$bytes} B";
                if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
                if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
                return round($bytes / 1073741824, 1) . ' GB';
            }
        );
    }

    // ─── Business Logic ──────────────────────────────────────────────────────────

    public function isReady(): bool
    {
        return $this->processing_status === 'ready';
    }

    public function isPending(): bool
    {
        return $this->processing_status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->processing_status === 'processing';
    }

    public function hasFailed(): bool
    {
        return $this->processing_status === 'failed';
    }

    public function markAsProcessing(): bool
    {
        return $this->update(['processing_status' => 'processing']);
    }

    public function markAsReady(array $metadata = []): bool
    {
        return $this->update([
            'processing_status' => 'ready',
            'processing_error'  => null,
            'metadata'          => array_merge($this->metadata ?? [], $metadata),
        ]);
    }

    public function markAsFailed(string $error): bool
    {
        return $this->update([
            'processing_status' => 'failed',
            'processing_error'  => $error,
        ]);
    }

    /**
     * Generate a signed S3 URL for downloading this file.
     */
    public function getSignedUrl(int $expirySeconds = 3600): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $this->s3_key,
            now()->addSeconds($expirySeconds),
            [
                'ResponseContentDisposition' => sprintf(
                    'attachment; filename="%s"',
                    $this->original_name
                ),
                'ResponseContentType' => $this->mime_type,
            ]
        );
    }

    /**
     * Generate a signed S3 URL for streaming (shorter TTL, inline content disposition).
     */
    public function getStreamingUrl(int $expiryMinutes = 15): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $this->s3_key,
            now()->addMinutes($expiryMinutes),
            [
                'ResponseContentDisposition' => 'inline',
                'ResponseContentType'        => $this->mime_type,
            ]
        );
    }

    public function getPages(): ?int
    {
        return data_get($this->metadata, 'pages');
    }

    public function getTableOfContents(): array
    {
        return data_get($this->metadata, 'toc', []);
    }
}
