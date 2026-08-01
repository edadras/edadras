<?php

namespace App\Models\Concerns;

/**
 * Stores a field as {"fa": "...", "tr": "...", "en": "..."} and resolves it
 * against the request locale with a fallback chain.
 */
trait HasTranslations
{
    public function translate(string $field, ?string $locale = null): ?string
    {
        $value = $this->getAttribute($field);

        if (! is_array($value)) {
            return $value;
        }

        $locale ??= app()->getLocale();

        foreach ([$locale, config('app.fallback_locale'), 'en', 'fa'] as $candidate) {
            if (! empty($value[$candidate])) {
                return $value[$candidate];
            }
        }

        return collect($value)->filter()->first();
    }

    /** Accepts a plain string and stores it under the current locale. */
    public function setTranslation(string $field, string $locale, string $value): static
    {
        $current = $this->getAttribute($field);
        $current = is_array($current) ? $current : [];
        $current[$locale] = $value;

        $this->setAttribute($field, $current);

        return $this;
    }

    public function getLabelAttribute(): ?string
    {
        return $this->translate($this->translatableLabel ?? 'name');
    }
}
