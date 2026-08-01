<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use App\Services\DatabaseTranslationLoader;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The apps download their whole string table from here on first run, so a
 * wording change ships without a store release.
 */
class TranslationController extends Controller
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function locales(): JsonResponse
    {
        return response()->json([
            'locales' => config('gymflow.locales'),
            'default' => config('gymflow.default_locale'),
        ]);
    }

    /** Every string for one language, ready to drop into the app's cache. */
    public function index(Request $request, string $locale): JsonResponse
    {
        abort_unless(array_key_exists($locale, config('gymflow.locales')), 404);

        $groups = ['general', 'checkin', 'booking', 'auth', 'ai', 'reports', 'panel', 'crm'];
        $lines = [];

        foreach ($groups as $group) {
            $lines[$group] = trans($group, [], $locale);
        }

        return response()->json([
            'locale' => $locale,
            'direction' => config("gymflow.locales.{$locale}.dir"),
            'version' => Translation::max('updated_at'),
            'lines' => $lines,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('settings.update');

        $data = $request->validate([
            'locale' => ['required', Rule::in(array_keys(config('gymflow.locales')))],
            'group' => ['required', 'string', 'max:60'],
            'key' => ['required', 'string', 'max:190'],
            'value' => ['required', 'string', 'max:2000'],
            'global' => ['nullable', 'boolean'],
        ]);

        // Only a platform operator may change a string for everyone.
        $tenantId = ($data['global'] ?? false) && $request->user()->isSuperAdmin()
            ? null
            : $this->tenancy->id();

        $translation = Translation::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'locale' => $data['locale'],
                'group' => $data['group'],
                'key' => $data['key'],
            ],
            ['value' => $data['value']],
        );

        DatabaseTranslationLoader::flush();

        return response()->json($translation, 201);
    }
}
