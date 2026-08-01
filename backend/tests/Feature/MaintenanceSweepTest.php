<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\HappyBirthday;
use App\Notifications\MembershipExpiring;
use App\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\ClubTestCase;

/** The nightly sweep, and the data it touches. */
class MaintenanceSweepTest extends ClubTestCase
{
    protected function memberWithAccount(string $code, ?string $birthDate): Member
    {
        $user = User::create([
            'tenant_id' => $this->club->id,
            'name' => "Member {$code}",
            'email' => "m{$code}@test.club",
            'phone' => '0912000'.$code,
            'password' => 'password',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'code' => $code,
            'first_name' => 'Sara',
            'last_name' => "M{$code}",
            'phone' => '0912000'.$code,
            'birth_date' => $birthDate,
        ]);
    }

    public function test_members_with_a_birthday_today_are_wished(): void
    {
        Notification::fake();

        $birthday = $this->memberWithAccount('9101', today()->subYears(30)->toDateString());
        $this->memberWithAccount('9102', today()->addDays(3)->subYears(25)->toDateString());

        $this->artisan('gymflow:maintenance --skip-insights')->assertSuccessful();

        Notification::assertSentTo($birthday->user, HappyBirthday::class);
        Notification::assertCount(1);
    }

    public function test_the_birthday_message_carries_the_discount_the_club_set(): void
    {
        Notification::fake();

        $member = $this->memberWithAccount('9103', today()->subYears(40)->toDateString());

        Setting::create([
            'tenant_id' => $this->club->id,
            'key' => 'birthday.discount_percent',
            'value' => ['percent' => 15],
        ]);

        $this->artisan('gymflow:maintenance --skip-insights')->assertSuccessful();

        Notification::assertSentTo(
            $member->user,
            fn (HappyBirthday $notification) => $notification->discountPercent === 15
        );
    }

    public function test_the_birthday_message_reaches_the_app_and_the_phone(): void
    {
        $member = $this->memberWithAccount('9104', today()->subYears(22)->toDateString());

        $this->artisan('gymflow:maintenance --skip-insights')->assertSuccessful();

        $notification = $member->user->fresh()->notifications()->first();

        $this->assertNotNull($notification);
        $this->assertSame('birthday', $notification->data['type']);
        $this->assertNotEmpty($notification->data['body']);
        $this->assertSame(
            ['database', 'push'],
            (new HappyBirthday($member))->via($member->user)
        );
    }

    public function test_a_member_without_an_account_is_skipped_not_crashed_on(): void
    {
        Member::create([
            'code' => '9105',
            'first_name' => 'No',
            'last_name' => 'Account',
            'phone' => '09120009105',
            'birth_date' => today()->subYears(35)->toDateString(),
        ]);

        $this->artisan('gymflow:maintenance --skip-insights')->assertSuccessful();
    }

    public function test_greetings_can_be_switched_off(): void
    {
        Notification::fake();
        config(['gymflow.renewal.birthday_greetings' => false]);

        $this->memberWithAccount('9106', today()->subYears(28)->toDateString());

        $this->artisan('gymflow:maintenance --skip-insights')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_renewal_reminders_still_go_out_alongside_them(): void
    {
        Notification::fake();

        $member = $this->memberWithAccount('9107', null);
        $membership = app(MembershipService::class)->sell($member, $this->plan('duration'), ['create_invoice' => false]);
        $membership->update(['ends_at' => today()->addDays(3)]);

        $this->artisan('gymflow:maintenance --skip-insights')->assertSuccessful();

        Notification::assertSentTo($member->user, MembershipExpiring::class);
    }

    public function test_health_notes_are_encrypted_at_rest(): void
    {
        $member = Member::create([
            'code' => '9108',
            'first_name' => 'Ali',
            'last_name' => 'Private',
            'phone' => '09120009108',
            'diseases' => 'asthma',
            'allergies' => 'penicillin',
            'passport_no' => 'X1234567',
        ]);

        $raw = DB::table('members')->where('id', $member->id)->first();

        // What the model reads back is the plain text...
        $fresh = $member->fresh();
        $this->assertSame('asthma', $fresh->diseases);
        $this->assertSame('penicillin', $fresh->allergies);
        $this->assertSame('X1234567', $fresh->passport_no);

        // ...but a raw dump of the table gives up nothing.
        $this->assertNotSame('asthma', $raw->diseases);
        $this->assertNotSame('penicillin', $raw->allergies);
        $this->assertNotSame('X1234567', $raw->passport_no);
        $this->assertStringNotContainsString('penicillin', json_encode($raw));
    }

    public function test_reception_can_still_search_by_national_id(): void
    {
        Member::create([
            'code' => '9109',
            'first_name' => 'Reza',
            'last_name' => 'Searchable',
            'phone' => '09120009109',
            'national_id' => '0012345678',
        ]);

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/members?search=0012345678')
            ->assertOk()
            ->assertJsonPath('data.0.code', '9109');
    }
}
