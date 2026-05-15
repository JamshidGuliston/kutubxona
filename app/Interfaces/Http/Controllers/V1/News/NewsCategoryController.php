<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\News;

use App\Domain\News\Models\NewsCategory;
use App\Interfaces\Http\Resources\News\NewsCategoryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class NewsCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $categories = NewsCategory::query()
            ->where('is_active', true)
            ->with('translations')
            ->withCount('news')
            ->orderBy('sort_order')
            ->get();

        return NewsCategoryResource::collection($categories);
    }
}
