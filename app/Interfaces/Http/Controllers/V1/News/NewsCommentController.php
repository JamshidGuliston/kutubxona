<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\News;

use App\Domain\News\Models\News;
use App\Domain\News\Models\NewsComment;
use App\Interfaces\Http\Resources\News\NewsCommentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class NewsCommentController extends Controller
{
    public function index(string $slug): AnonymousResourceCollection
    {
        $news = $this->findNewsBySlug($slug);

        $comments = NewsComment::query()
            ->where('news_id', $news->id)
            ->whereNull('parent_id')
            ->where('is_approved', true)
            ->with(['user', 'replies' => fn ($q) => $q->where('is_approved', true)->with('user')])
            ->withCount('replies')
            ->orderByDesc('created_at')
            ->get();

        return NewsCommentResource::collection($comments);
    }

    public function store(Request $request, string $slug): NewsCommentResource
    {
        $request->validate([
            'body'      => 'required|string|min:2|max:2000',
            'parent_id' => 'nullable|integer|exists:news_comments,id',
        ]);

        $news = $this->findNewsBySlug($slug);

        $comment = NewsComment::create([
            'news_id'   => $news->id,
            'user_id'   => $request->user()->id,
            'parent_id' => $request->integer('parent_id') ?: null,
            'body'      => $request->string('body')->toString(),
            'is_approved' => false,
        ]);

        $comment->load('user');
        return new NewsCommentResource($comment);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $comment = NewsComment::findOrFail($id);

        if ($comment->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Not your comment.'], 403);
        }

        $comment->delete();
        return response()->json(['success' => true]);
    }

    private function findNewsBySlug(string $slug): News
    {
        $news = News::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
            ->live()
            ->first();

        if (! $news) {
            throw new NotFoundHttpException("News '{$slug}' not found.");
        }
        return $news;
    }
}
