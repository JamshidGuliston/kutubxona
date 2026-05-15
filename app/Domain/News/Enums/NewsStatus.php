<?php

declare(strict_types=1);

namespace App\Domain\News\Enums;

enum NewsStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Qoralama',
            self::Published => 'Nashr qilingan',
            self::Archived  => 'Arxivlangan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft     => 'gray',
            self::Published => 'success',
            self::Archived  => 'warning',
        };
    }
}
