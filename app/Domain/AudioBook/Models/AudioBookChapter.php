<?php

declare(strict_types=1);

namespace App\Domain\AudioBook\Models;

use App\Infrastructure\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $audiobook_id
 * @property string $title
 * @property int $chapter_number
 * @property string $s3_key
 * @property string $s3_bucket
 * @property int|null $duration
 * @property int|null $file_size
 * @property string $mime_type
 * @property array|null $waveform_data
 * @property string $processing_status
 */
final class AudioBookChapter extends Model
{
    protected $table = 'audiobook_chapters';

    protected $fillable = [
        'tenant_id',
        'audiobook_id',
        'title',
        'chapter_number',
        's3_key',
        's3_bucket',
        'duration',
        'file_size',
        'mime_type',
        'waveform_data',
        'processing_status',
    ];

    protected $casts = [
        'chapter_number'   => 'integer',
        'duration'         => 'integer',
        'file_size'        => 'integer',
        'waveform_data'    => 'array',
    ];

    protected $hidden = ['s3_key', 's3_bucket', 'tenant_id'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $chapter): void {
            if (app()->has('tenant') && empty($chapter->tenant_id)) {
                $chapter->tenant_id = app('tenant')->id;
            }
        });
    }

    public function audiobook(): BelongsTo
    {
        return $this->belongsTo(AudioBook::class, 'audiobook_id');
    }

    public function isReady(): bool
    {
        return $this->processing_status === 'ready';
    }

    public function getDurationFormatted(): string
    {
        $seconds = $this->duration ?? 0;
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

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

    public function markAsReady(): bool
    {
        return $this->update(['processing_status' => 'ready']);
    }

    public function markAsFailed(string $error): bool
    {
        return $this->update(['processing_status' => 'failed']);
    }
}
