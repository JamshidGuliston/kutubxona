<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Localization;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TranslationController extends Controller
{
    public function show(string $locale): JsonResponse
    {
        $locale = strtolower(preg_replace('/[^a-z\-]/i', '', $locale));
        $path = lang_path("{$locale}.json");

        if (! is_file($path)) {
            throw new NotFoundHttpException("Translations for locale '{$locale}' not found.");
        }

        $payload = Cache::remember(
            "translations.json.{$locale}",
            3600,
            fn (): array => [
                'locale'       => $locale,
                'translations' => json_decode(file_get_contents($path), true) ?? [],
            ]
        );

        return response()->json($payload);
    }
}
