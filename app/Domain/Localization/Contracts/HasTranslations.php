<?php

declare(strict_types=1);

namespace App\Domain\Localization\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

interface HasTranslations
{
    /**
     * Returns all translations for this entity.
     */
    public function translations(): HasMany;

    /**
     * Returns the translation for the current app locale (eager-loadable).
     */
    public function translation(): HasOne;

    /**
     * Returns the value of a translatable field for the requested locale,
     * falling back to the tenant default locale when missing.
     */
    public function trans(string $field, ?string $locale = null): ?string;

    /**
     * Returns the list of translatable field names.
     *
     * @return list<string>
     */
    public function getTranslatableFields(): array;

    /**
     * Returns the fully-qualified class name of the translation model.
     */
    public function getTranslationModelClass(): string;
}
