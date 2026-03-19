<?php

declare(strict_types=1);

namespace App\Domain\Book\Enums;

enum BookStatus: string
{
    case Draft      = 'draft';
    case Processing = 'processing';
    case Published  = 'published';
    case Archived   = 'archived';
    case Rejected   = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft      => 'Draft',
            self::Processing => 'Processing',
            self::Published  => 'Published',
            self::Archived   => 'Archived',
            self::Rejected   => 'Rejected',
        };
    }

    public function isVisible(): bool
    {
        return $this === self::Published;
    }

    public function canTransitionTo(self $newStatus): bool
    {
        return match ($this) {
            self::Draft      => in_array($newStatus, [self::Processing, self::Published]),
            self::Processing => in_array($newStatus, [self::Published, self::Draft, self::Rejected]),
            self::Published  => in_array($newStatus, [self::Archived, self::Draft]),
            self::Archived   => in_array($newStatus, [self::Published, self::Draft]),
            self::Rejected   => in_array($newStatus, [self::Draft]),
        };
    }
}
