<?php

declare(strict_types=1);

namespace App\Domain\News\Observers;

use App\Domain\News\Models\NewsLike;

final class NewsLikeObserver
{
    public function created(NewsLike $like): void
    {
        $like->news?->recalculateCounts();
    }

    public function deleted(NewsLike $like): void
    {
        $like->news?->recalculateCounts();
    }
}
