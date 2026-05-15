<?php

declare(strict_types=1);

namespace App\Domain\News\Observers;

use App\Domain\News\Enums\NewsStatus;
use App\Domain\News\Models\News;

final class NewsObserver
{
    public function saving(News $news): void
    {
        if ($news->status === NewsStatus::Published
            && $news->published_at === null
        ) {
            $news->published_at = now();
        }
    }
}
