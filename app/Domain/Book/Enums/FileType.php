<?php

declare(strict_types=1);

namespace App\Domain\Book\Enums;

enum FileType: string
{
    case PDF  = 'pdf';
    case EPUB = 'epub';
    case MP3  = 'mp3';
    case M4B  = 'm4b';

    public function isEbook(): bool
    {
        return in_array($this, [self::PDF, self::EPUB]);
    }

    public function isAudio(): bool
    {
        return in_array($this, [self::MP3, self::M4B]);
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::PDF  => 'application/pdf',
            self::EPUB => 'application/epub+zip',
            self::MP3  => 'audio/mpeg',
            self::M4B  => 'audio/mp4',
        };
    }

    public function maxSizeMb(): int
    {
        return match ($this) {
            self::PDF  => (int) config('storage.max_book_file_mb', 100),
            self::EPUB => (int) config('storage.max_book_file_mb', 100),
            self::MP3  => (int) config('storage.max_audio_file_mb', 300),
            self::M4B  => (int) config('storage.max_audio_file_mb', 300),
        };
    }
}
