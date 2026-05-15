<?php

declare(strict_types=1);

namespace App\Domain\News\Observers;

use App\Domain\News\Models\NewsComment;

final class NewsCommentObserver
{
    public function creating(NewsComment $comment): void
    {
        if ($comment->parent_id === null) {
            return;
        }
        $parent = NewsComment::find($comment->parent_id);
        if ($parent && $parent->parent_id !== null) {
            throw new \InvalidArgumentException(
                'News comments support only one level of nesting; cannot reply to a reply.'
            );
        }
    }

    public function saved(NewsComment $comment): void
    {
        if ($comment->wasChanged('is_approved')) {
            $comment->news?->recalculateCounts();
        }
    }

    public function deleted(NewsComment $comment): void
    {
        $comment->news?->recalculateCounts();
    }
}
