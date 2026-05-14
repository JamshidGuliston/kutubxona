<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\V1\Localization;

use App\Domain\Localization\Models\TenantLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

final class LanguageController extends Controller
{
    public function index(): JsonResponse
    {
        $tenant = app('tenant');
        $cacheKey = "tenant.{$tenant->id}.languages.public";

        $payload = Cache::remember($cacheKey, 300, function () use ($tenant): array {
            $languages = TenantLanguage::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            return [
                'default_locale' => $tenant->default_locale,
                'languages' => $languages->map(fn (TenantLanguage $l) => [
                    'code'        => $l->code,
                    'name'        => $l->name,
                    'native_name' => $l->native_name,
                    'flag'        => $l->flag_emoji,
                    'is_default'  => $l->is_default,
                ])->all(),
            ];
        });

        return response()->json($payload);
    }
}
