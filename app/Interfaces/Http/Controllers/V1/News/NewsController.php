<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\News;

use App\Domain\News\Models\News;
use App\Interfaces\Http\Resources\News\NewsResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class NewsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = News::query()
            ->live()
            ->with(['translations', 'category.translations', 'author', 'likes'])
            ->recent();

        if ($categorySlug = $request->string('category')->toString()) {
            $query->whereHas('category.translations', fn ($q) => $q->where('slug', $categorySlug));
        }

        if ($search = $request->string('search')->toString()) {
            $query->whereHas('translations', fn ($q) => $q->where('title', 'ilike', "%{$search}%"));
        }

        return NewsResource::collection($query->paginate(12));
    }

    public function featured(): AnonymousResourceCollection
    {
        $rows = News::query()
            ->live()
            ->featured()
            ->with(['translations', 'category.translations', 'author', 'likes'])
            ->recent()
            ->limit(5)
            ->get();

        return NewsResource::collection($rows);
    }

    public function latest(): AnonymousResourceCollection
    {
        $rows = News::query()
            ->live()
            ->with(['translations', 'category.translations', 'author', 'likes'])
            ->recent()
            ->limit(10)
            ->get();

        return NewsResource::collection($rows);
    }

    public function show(string $slug): NewsResource
    {
        $news = $this->findBySlug($slug);
        $news->incrementViewCount();

        return new NewsResource($news);
    }

    public function related(string $slug): AnonymousResourceCollection
    {
        $news = $this->findBySlug($slug);

        $query = News::query()
            ->live()
            ->where('id', '!=', $news->id)
            ->with(['translations', 'category.translations', 'author', 'likes'])
            ->recent()
            ->limit(4);

        if ($news->news_category_id !== null) {
            $query->where('news_category_id', $news->news_category_id);
        }

        return NewsResource::collection($query->get());
    }

    private function findBySlug(string $slug): News
    {
        $news = News::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
            ->live()
            ->with(['translations', 'category.translations', 'author', 'likes'])
            ->first();

        if (! $news) {
            throw new NotFoundHttpException("News '{$slug}' not found.");
        }
        return $news;
    }
}
