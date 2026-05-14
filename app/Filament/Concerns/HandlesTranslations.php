<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Localization\Contracts\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared Filament Create/Edit page hooks for dual-write translation handling
 * during Phase B.
 *
 * - Form fields use the path `translations.{locale}.{field}`.
 * - On save, fields from the DEFAULT locale are also written to the parent
 *   model columns (legacy compatibility). Non-default locales write only to
 *   the translation table.
 * - On edit, the trait pre-fills tabs from existing translation rows,
 *   falling back to the parent columns for the default locale when no
 *   translation row exists yet.
 */
trait HandlesTranslations
{
    /** @var array<string, array<string, mixed>> */
    protected array $translationsData = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->extractTranslations($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractTranslations($data);
    }

    protected function afterCreate(): void
    {
        $this->persistTranslations();
    }

    protected function afterSave(): void
    {
        $this->persistTranslations();
    }

    protected function fillForm(): void
    {
        // Default fill happens first
        parent::fillForm();

        if (! $this->record instanceof HasTranslations) {
            return;
        }

        $state = $this->form->getRawState();
        $translatableFields = $this->record->getTranslatableFields();
        $defaultLocale = $this->resolveDefaultLocale();
        $translationsBag = [];

        // Load existing translation rows
        $existing = $this->record->translations->keyBy('locale');

        // Pre-fill ALL active tenant languages so each tab has its slot
        $languages = $this->getActiveLanguageCodes();
        foreach ($languages as $locale) {
            foreach ($translatableFields as $field) {
                $value = $existing[$locale]?->{$field} ?? null;

                // Legacy fallback: if this is the default locale and no
                // translation row exists yet, read from the parent column.
                if (! $value && $locale === $defaultLocale) {
                    $value = $this->record->getAttributes()[$field] ?? null;
                }

                $translationsBag[$locale][$field] = $value;
            }
        }

        $state['translations'] = $translationsBag;
        $this->form->fill($state);
    }

    private function extractTranslations(array $data): array
    {
        $this->translationsData = $data['translations'] ?? [];
        unset($data['translations']);

        $defaultLocale = $this->resolveDefaultLocale();
        $translatableFields = $this->resolveTranslatableFields();

        // Dual-write: copy default-locale fields into the main payload so the
        // parent columns (books.title, etc.) stay in sync.
        if (! empty($this->translationsData[$defaultLocale] ?? null)) {
            foreach ($translatableFields as $field) {
                $value = $this->translationsData[$defaultLocale][$field] ?? null;
                if (filled($value)) {
                    $data[$field] = $value;
                }
            }
        }

        return $data;
    }

    private function persistTranslations(): void
    {
        if (empty($this->translationsData) || ! $this->record instanceof HasTranslations) {
            return;
        }

        $translatableFields = $this->record->getTranslatableFields();
        $primaryField = $translatableFields[0] ?? null;

        foreach ($this->translationsData as $locale => $fields) {
            // Empty primary field → remove any existing translation row
            if ($primaryField === null || empty($fields[$primaryField])) {
                $this->record->translations()->where('locale', $locale)->delete();
                continue;
            }

            $payload = array_intersect_key(
                $fields,
                array_flip($translatableFields)
            );

            $this->record->translations()->updateOrCreate(
                ['locale' => $locale],
                $payload
            );
        }
    }

    private function resolveDefaultLocale(): string
    {
        $tenant = app()->has('tenant')
            ? app('tenant')
            : auth()->user()?->tenant;

        return $tenant?->default_locale ?? config('app.locale', 'uz');
    }

    /**
     * @return list<string>
     */
    private function resolveTranslatableFields(): array
    {
        if (isset($this->record) && $this->record instanceof HasTranslations) {
            return $this->record->getTranslatableFields();
        }
        $modelClass = static::getResource()::getModel();
        return $modelClass::TRANSLATABLE_FIELDS ?? [];
    }

    /**
     * @return list<string>
     */
    private function getActiveLanguageCodes(): array
    {
        $tenantId = auth()->user()?->tenant_id;
        if (! $tenantId) {
            return [config('app.locale', 'uz')];
        }
        return \App\Domain\Localization\Models\TenantLanguage::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('code')
            ->all();
    }
}
