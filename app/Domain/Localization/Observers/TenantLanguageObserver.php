<?php

declare(strict_types=1);

namespace App\Domain\Localization\Observers;

use App\Domain\Localization\Models\TenantLanguage;

final class TenantLanguageObserver
{
    public function saving(TenantLanguage $language): void
    {
        if (! $language->is_default) {
            return;
        }

        TenantLanguage::query()
            ->where('tenant_id', $language->tenant_id)
            ->when($language->exists, fn ($q) => $q->where('id', '!=', $language->id))
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    public function deleting(TenantLanguage $language): void
    {
        if (! $language->is_default) {
            return;
        }

        $nextDefault = TenantLanguage::query()
            ->where('tenant_id', $language->tenant_id)
            ->where('id', '!=', $language->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        if ($nextDefault) {
            $nextDefault->update(['is_default' => true]);
        }
    }
}
