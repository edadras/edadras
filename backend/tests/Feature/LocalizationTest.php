<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Translation;
use App\Services\DatabaseTranslationLoader;
use Tests\ClubTestCase;

/** Three languages, all served from the database, RTL included. */
class LocalizationTest extends ClubTestCase
{
    public function test_the_locale_list_is_published_for_the_apps(): void
    {
        $response = $this->getJson('/api/v1/locales');

        $response->assertOk()
            ->assertJsonPath('locales.fa.dir', 'rtl')
            ->assertJsonPath('locales.tr.dir', 'ltr')
            ->assertJsonPath('locales.en.dir', 'ltr');
    }

    public function test_the_apps_can_download_a_whole_language(): void
    {
        $response = $this->getJson('/api/v1/translations/fa');

        $response->assertOk()
            ->assertJsonPath('direction', 'rtl')
            ->assertJsonPath('lines.checkin.allowed', 'خوش آمدید. ورود ثبت شد.');
    }

    public function test_an_unsupported_locale_is_rejected(): void
    {
        $this->getJson('/api/v1/translations/de')->assertNotFound();
    }

    public function test_the_header_picks_the_response_language(): void
    {
        $member = Member::create(['code' => '1', 'first_name' => 'A', 'last_name' => 'B', 'phone' => '0912']);

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders() + ['X-Locale' => 'tr'])
            ->postJson('/api/v1/attendance/check-in', ['qr_token' => $member->qr_token]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Bu üyenin aktif bir üyeliği yok.');
    }

    public function test_the_response_declares_its_language(): void
    {
        $this->asUser()
            ->withHeaders($this->clubHeaders() + ['X-Locale' => 'fa'])
            ->getJson('/api/v1/dashboard')
            ->assertHeader('Content-Language', 'fa');
    }

    public function test_a_database_string_overrides_the_shipped_one(): void
    {
        Translation::create([
            'tenant_id' => null,
            'locale' => 'en',
            'group' => 'checkin',
            'key' => 'allowed',
            'value' => 'Come on in.',
        ]);

        DatabaseTranslationLoader::flush();
        app('translator')->setLoaded([]);

        $this->assertSame('Come on in.', __('checkin.allowed', [], 'en'));
    }

    public function test_a_club_can_override_a_string_just_for_itself(): void
    {
        Translation::create([
            'tenant_id' => $this->club->id,
            'locale' => 'en',
            'group' => 'checkin',
            'key' => 'allowed',
            'value' => 'Welcome to Test Club.',
        ]);

        DatabaseTranslationLoader::flush();
        app('translator')->setLoaded([]);

        $this->assertSame('Welcome to Test Club.', __('checkin.allowed', [], 'en'));
    }

    public function test_a_translatable_field_resolves_against_the_locale(): void
    {
        $plan = MembershipPlan::create([
            'name' => ['fa' => 'ماهانه', 'tr' => 'Aylık', 'en' => 'Monthly'],
            'type' => 'duration',
            'duration_days' => 30,
            'price' => 100,
        ]);

        $this->assertSame('ماهانه', $plan->translate('name', 'fa'));
        $this->assertSame('Aylık', $plan->translate('name', 'tr'));
        $this->assertSame('Monthly', $plan->translate('name', 'en'));
    }

    public function test_a_missing_translation_falls_back_instead_of_going_blank(): void
    {
        $plan = MembershipPlan::create([
            'name' => ['en' => 'Only English'],
            'type' => 'duration',
            'duration_days' => 30,
            'price' => 100,
        ]);

        $this->assertSame('Only English', $plan->translate('name', 'tr'));
    }

    public function test_the_seeded_price_list_is_translated_into_all_three(): void
    {
        $plan = MembershipPlan::orderBy('sort_order')->first();

        foreach (['fa', 'tr', 'en'] as $locale) {
            $this->assertNotEmpty($plan->translate('name', $locale), "Missing {$locale} name.");
        }
    }
}
