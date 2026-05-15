<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\News;

use App\Domain\News\Models\News;
use App\Domain\News\Models\NewsLike;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class NewsLikeController extends Controller
{
    public function toggle(Request $request, string $slug): JsonResponse
    {
        $news = News::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
            ->live()
            ->first();

        if (! $news) {
            throw new NotFoundHttpException("News '{$slug}' not found.");
        }

        $userId = $request->user()->id;

        $existing = NewsLike::query()
            ->where('news_id', $news->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            $existing->delete();
            $liked = false;
        } else {
            NewsLike::create([
                'news_id' => $news->id,
                'user_id' => $userId,
            ]);
            $liked = true;
        }

        $news->refresh();

        return response()->json([
            'liked'      => $liked,
            'like_count' => $news->like_count,
        ]);
    }
}
