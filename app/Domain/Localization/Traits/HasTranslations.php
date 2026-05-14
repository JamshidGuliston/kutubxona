<?php

declare(strict_types=1);

namespace App\Domain\Localization\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Magic + explicit translation accessor behavior.
 *
 * Consumers must:
 *   - implement App\Domain\Localization\Contracts\HasTranslations
 *   - define const TRANSLATABLE_FIELDS (list of translatable field names)
 *   - define const TRANSLATION_MODEL (FQCN of translation model)
 *
 * Phase A behavior: when the real column exists on the parent model and is
 * non-empty, it wins. Only when the real column is null/missing do we look up
 * the translation row. This makes the trait safe to add to existing models
 * without breaking anything.
 */
trait HasTranslations
{
    public function translations(): HasMany
    {
        return $this->hasMany($this->getTranslationModelClass());
    }

    public function translation(): HasOne
    {
        return $this->hasOne($this->getTranslationModelClass())
            ->where('locale', app()->getLocale());
    }

    public function trans(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->get();

        $primary = $translations->firstWhere('locale', $locale);
        if ($primary && filled($primary->{$field})) {
            return $primary->{$field};
        }

        $fallbackLocale = $this->tenantDefaultLocale();
        if ($fallbackLocale && $fallbackLocale !== $locale) {
            $fallback = $translations->firstWhere('locale', $fallbackLocale);
            if ($fallback && filled($fallback->{$field})) {
                return $fallback->{$field};
            }
        }

        return null;
    }

    public function getAttribute($key)
    {
        // Real columns / existing accessors / loaded relations win first
        if ($this->hasGetMutator($key)
            || $this->hasAttributeGetMutator($key)
            || array_key_exists($key, $this->attributes)
            || $this->relationLoaded($key)
            || method_exists($this, $key)
        ) {
            return parent::getAttribute($key);
        }

        if (in_array($key, $this->getTranslatableFields(), true)) {
            return $this->trans($key);
        }

        return parent::getAttribute($key);
    }

    public function getTranslatableFields(): array
    {
        return static::TRANSLATABLE_FIELDS ?? [];
    }

    public function getTranslationModelClass(): string
    {
        if (! defined(static::class . '::TRANSLATION_MODEL')) {
            throw new \LogicException(
                static::class . ' must define const TRANSLATION_MODEL.'
            );
        }
        return static::TRANSLATION_MODEL;
    }

    /**
     * Looks up the tenant default locale via the related tenant.
     */
    protected function tenantDefaultLocale(): ?string
    {
        /** @var Model $this */
        if (! isset($this->tenant_id)) {
            return config('app.locale', 'uz');
        }

        $tenant = $this->relationLoaded('tenant')
            ? $this->tenant
            : \App\Domain\Tenant\Models\Tenant::find($this->tenant_id);

        return $tenant?->default_locale ?? config('app.locale', 'uz');
    }
}
