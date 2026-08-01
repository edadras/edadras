<?php

namespace Tests\Feature;

use App\Models\Translation;
use App\Models\User;
use Tests\ClubTestCase;

/** The Super Admin's wording screen: platform defaults every club inherits. */
class PlatformTemplatesTest extends ClubTestCase
{
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@gymflow.test',
            'password' => 'password',
            'is_super_admin' => true,
        ]);
    }

    protected function asSuperAdmin(): static
    {
        $this->actingAs($this->superAdmin, 'sanctum');

        return $this;
    }

    public function test_it_lists_every_string_the_platform_ships(): void
    {
        $response = $this->asSuperAdmin()
            ->getJson('/api/v1/platform/templates?locale=fa')
            ->assertOk();

        $this->assertGreaterThan(100, $response->json('total'));
        $this->assertSame('rtl', $response->json('direction'));
        $this->assertContains('reports', $response->json('groups'));
    }

    public function test_it_narrows_to_one_group_and_to_a_search(): void
    {
        $group = $this->asSuperAdmin()
            ->getJson('/api/v1/platform/templates?locale=en&group=checkin')
            ->assertOk();

        foreach ($group->json('lines') as $line) {
            $this->assertSame('checkin', $line['group']);
        }

        $search = $this->asSuperAdmin()
            ->getJson('/api/v1/platform/templates?locale=en&group=checkin&search=session')
            ->assertOk();

        $this->assertLessThan(count($group->json('lines')), count($search->json('lines')));
        $this->assertNotEmpty($search->json('lines'));
    }

    public function test_rewriting_a_default_changes_what_the_apps_download(): void
    {
        $this->asSuperAdmin()
            ->putJson('/api/v1/platform/templates', [
                'locale' => 'en',
                'group' => 'checkin',
                'key' => 'allowed',
                'value' => 'Come on in.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('translations', [
            'tenant_id' => null,
            'locale' => 'en',
            'group' => 'checkin',
            'key' => 'allowed',
            'value' => 'Come on in.',
        ]);

        $lines = $this->getJson('/api/v1/translations/en')->assertOk()->json('lines');

        $this->assertSame('Come on in.', $lines['checkin']['allowed']);
    }

    public function test_a_rewritten_string_is_flagged_as_overridden(): void
    {
        $this->asSuperAdmin()->putJson('/api/v1/platform/templates', [
            'locale' => 'en', 'group' => 'checkin', 'key' => 'allowed', 'value' => 'Come on in.',
        ])->assertCreated();

        $line = collect($this->asSuperAdmin()->getJson('/api/v1/platform/templates?locale=en&group=checkin')->json('lines'))
            ->firstWhere('key', 'allowed');

        $this->assertTrue($line['overridden']);
        $this->assertSame('Come on in.', $line['value']);
    }

    public function test_resetting_puts_the_shipped_wording_back(): void
    {
        $this->asSuperAdmin()->putJson('/api/v1/platform/templates', [
            'locale' => 'en', 'group' => 'checkin', 'key' => 'allowed', 'value' => 'Come on in.',
        ])->assertCreated();

        $this->asSuperAdmin()->deleteJson('/api/v1/platform/templates', [
            'locale' => 'en', 'group' => 'checkin', 'key' => 'allowed',
        ])->assertOk();

        $this->assertSame(
            'Welcome. Entry recorded.',
            $this->getJson('/api/v1/translations/en')->json('lines.checkin.allowed')
        );
    }

    public function test_a_club_override_survives_a_platform_rewrite(): void
    {
        Translation::create([
            'tenant_id' => $this->club->id,
            'locale' => 'en',
            'group' => 'checkin',
            'key' => 'allowed',
            'value' => 'Club wording',
        ]);

        $this->asSuperAdmin()->putJson('/api/v1/platform/templates', [
            'locale' => 'en', 'group' => 'checkin', 'key' => 'allowed', 'value' => 'Platform wording',
        ])->assertCreated();

        $lines = $this->getJson('/api/v1/translations/en', $this->clubHeaders())->json('lines');

        $this->assertSame('Club wording', $lines['checkin']['allowed']);
    }

    public function test_it_counts_how_many_clubs_rewrote_a_string(): void
    {
        Translation::create([
            'tenant_id' => $this->club->id, 'locale' => 'en', 'group' => 'checkin', 'key' => 'allowed', 'value' => 'Club wording',
        ]);

        $line = collect($this->asSuperAdmin()->getJson('/api/v1/platform/templates?locale=en&group=checkin')->json('lines'))
            ->firstWhere('key', 'allowed');

        $this->assertSame(1, $line['clubs_overriding']);
    }

    public function test_the_overrides_list_shows_which_club_changed_what(): void
    {
        Translation::create([
            'tenant_id' => $this->club->id, 'locale' => 'en', 'group' => 'checkin', 'key' => 'allowed', 'value' => 'Club wording',
        ]);

        $this->asSuperAdmin()
            ->getJson('/api/v1/platform/template-overrides')
            ->assertOk()
            ->assertJsonPath('data.0.tenant.slug', $this->club->slug)
            ->assertJsonPath('data.0.value', 'Club wording');
    }

    public function test_an_unknown_locale_or_group_is_a_404(): void
    {
        $this->asSuperAdmin()->getJson('/api/v1/platform/templates?locale=de')->assertNotFound();
        $this->asSuperAdmin()->getJson('/api/v1/platform/templates?locale=en&group=nope')->assertNotFound();
    }

    public function test_a_club_owner_cannot_reach_the_platform_wording(): void
    {
        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/platform/templates')
            ->assertForbidden();
    }
}
