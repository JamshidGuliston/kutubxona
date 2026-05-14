<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Localization\Contracts\HasTranslations;
use App\Domain\Localization\Models\TenantLanguage;
use Illuminate\Database\Eloquent\Model;

/**
 * Filament Create/Edit page trait for dual-write translation handling.
 *
 * Form data must use `translations.{locale}.{field}` keys for translatable
 * fields. On save, this trait:
 *   1. Extracts the translations sub-array out of the form data.
 *   2. Persists the parent record (Filament handles this with the remaining data).
 *   3. For each locale with non-empty primary field: upsert a translation row.
 *   4. For the tenant's DEFAULT locale, also copy translation values into the
 *      parent columns (Phase B dual-write — keeps old read paths working).
 *
 * The Edit-page form is pre-filled from the translation table; for the default
 * locale we fall back to parent-column values if no translation row exists yet.
 */
trait HandlesTranslations
{
    /** @var array<string, array<string, mixed>> */
    protected array $translationsData = [];

    protected function extractTranslations(array $data): array
    {
        $this->translationsData = $data['translations'] ?? [];
        unset($data['translations']);
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractTranslations($data);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->mutateFormDataBeforeSave($data);
    }

    protected function afterSave(): void
    {
        $this->persistTranslations();
    }

    protected function afterCreate(): void
    {
        $this->persistTranslations();
    }

    protected function fillForm(): void
    {
        parent::fillForm();

        if (! $this->record instanceof HasTranslations) {
            return;
        }

        $translatableFields = $this->record->getTranslatableFields();
        $existing = $this->record->translations->keyBy('locale');
        $defaultLocale = $this->resolveDefaultLocale();

        $payload = $this->form->getRawState();
        $translationsBag = [];

        // Pre-fill each active language
        foreach ($this->activeLanguages() as $lang) {
            foreach ($translatableFields as $field) {
                $value = $existing->get($lang->code)?->{$field};

                // Default-locale fallback: if no translation row, read from parent column
                if (blank($value) && $lang->code === $defaultLocale) {
                    $value = $this->record->getAttributes()[$field] ?? null;
                }

                $translationsBag[$lang->code][$field] = $value;
            }
        }

        $payload['translations'] = $translationsBag;
        $this->form->fill($payload);
    }

    private function persistTranslations(): void
    {
        if (empty($this->translationsData) || ! $this->record instanceof HasTranslations) {
            return;
        }

        $translatableFields = $this->record->getTranslatableFields();
        $primaryField = $translatableFields[0] ?? null;
        if ($primaryField === null) {
            return;
        }

        $defaultLocale = $this->resolveDefaultLocale();

        foreach ($this->translationsData as $locale => $fields) {
            $locale = (string) $locale;
            $primary = $fields[$primaryField] ?? null;

            if (blank($primary)) {
                // Empty primary field => drop any stale translation row for this locale
                $this->record->translations()->where('locale', $locale)->delete();
                continue;
            }

            $payload = array_intersect_key(
                array_filter($fields, fn ($v) => $v !== null),
                array_flip($translatableFields)
            );

            $this->record->translations()->updateOrCreate(
                ['locale' => $locale],
                $payload
            );

            // Phase B dual-write: for default locale, also sync parent columns
            if ($locale === $defaultLocale) {
                $parentPayload = array_intersect_key($payload, array_flip($translatableFields));
                if (! empty($parentPayload)) {
                    $this->syncParentColumns($parentPayload);
                }
            }
        }
    }

    /**
     * Write translatable fields back to the parent table for Phase B dual-write.
     * Skips fields that aren't actual columns on the parent model.
     */
    private function syncParentColumns(array $payload): void
    {
        /** @var Model $record */
        $record = $this->record;
        $fillable = $record->getFillable();
        $columns = array_keys($payload);
        $writable = array_filter($columns, fn ($c) => in_array($c, $fillable, true));

        if (empty($writable)) {
            return;
        }

        $updates = array_intersect_key($payload, array_flip($writable));
        $record->forceFill($updates)->saveQuietly();
    }

    private function resolveDefaultLocale(): string
    {
        $tenantId = auth()->user()?->tenant_id ?? null;
        if ($tenantId !== null) {
            $default = TenantLanguage::query()
                ->where('tenant_id', $tenantId)
                ->where('is_default', true)
                ->value('code');
            if ($default) {
                return $default;
            }
        }
        return config('app.locale', 'uz');
    }

    /**
     * @return \Illuminate\Support\Collection<int, TenantLanguage>
     */
    private function activeLanguages()
    {
        $tenantId = auth()->user()?->tenant_id ?? null;
        return TenantLanguage::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }
}
