<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Coach;
use App\Models\GymClass;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Product;
use App\Models\User;
use App\Services\BookingService;
use App\Services\InvoiceService;
use App\Services\MembershipService;
use App\Services\TenantProvisioningService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A fully populated club so the apps have something to show on first run.
 * Everything is created through the real services, so the demo data obeys
 * the same rules as production data.
 */
class DemoClubSeeder extends Seeder
{
    public function run(): void
    {
        $provisioning = app(TenantProvisioningService::class);
        $tenancy = app(TenantContext::class);

        $tenant = $provisioning->create(
            [
                'name' => 'باشگاه آرمان',
                'type' => 'gym',
                'phone' => '+98 21 8800 1122',
                'address' => 'تهران، خیابان ولیعصر',
                'locale' => 'fa',
            ],
            [
                'name' => 'آرمان رضایی',
                'email' => 'owner@arman.club',
                'phone' => '09120000001',
                'password' => 'password',
            ],
            'professional',
        );

        $tenancy->run($tenant, function () use ($tenant) {
            $this->seedStaff($tenant);
            $coaches = $this->seedCoaches();
            $this->seedProducts();
            $this->seedClasses($coaches);
            $this->seedMembers($tenant);
        });

        $this->command?->info("Demo club ready: {$tenant->name} ({$tenant->slug}) — owner@arman.club / password");
    }

    protected function seedStaff($tenant): void
    {
        $roles = $tenant->roles()->pluck('id', 'slug');

        $staff = [
            ['name' => 'سارا پذیرش', 'email' => 'reception@arman.club', 'role' => 'reception'],
            ['name' => 'مهدی صندوق', 'email' => 'cashier@arman.club', 'role' => 'cashier'],
            ['name' => 'نگار حسابدار', 'email' => 'accountant@arman.club', 'role' => 'accountant'],
        ];

        foreach ($staff as $person) {
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $person['name'],
                'email' => $person['email'],
                'password' => 'password',
                'locale' => 'fa',
                'status' => 'active',
            ]);

            $user->roles()->attach($roles[$person['role']]);
        }
    }

    /** @return Collection<int, Coach> */
    protected function seedCoaches(): Collection
    {
        return collect([
            ['first_name' => 'رضا', 'last_name' => 'کریمی', 'specialties' => ['bodybuilding', 'strength'], 'contract_type' => 'fixed', 'salary_amount' => 25_000_000],
            ['first_name' => 'مینا', 'last_name' => 'صادقی', 'specialties' => ['yoga', 'pilates'], 'contract_type' => 'per_session', 'salary_amount' => 800_000],
            ['first_name' => 'کاوه', 'last_name' => 'نوری', 'specialties' => ['swimming'], 'contract_type' => 'percentage', 'commission_percent' => 40],
        ])->map(fn (array $coach) => Coach::create($coach + [
            'phone' => '0912'.random_int(1000000, 9999999),
            'hired_at' => now()->subMonths(random_int(3, 24)),
            'status' => 'active',
        ]));
    }

    protected function seedProducts(): void
    {
        $products = [
            ['name' => 'وی پروتئین ۲ کیلویی', 'category' => 'supplement', 'price' => 4_500_000, 'cost' => 3_400_000, 'stock' => 12, 'min_stock' => 3],
            ['name' => 'کراتین مونوهیدرات', 'category' => 'supplement', 'price' => 1_200_000, 'cost' => 850_000, 'stock' => 20, 'min_stock' => 5],
            ['name' => 'آب معدنی', 'category' => 'drink', 'price' => 30_000, 'cost' => 18_000, 'stock' => 240, 'min_stock' => 48],
            ['name' => 'تیشرت باشگاه', 'category' => 'clothing', 'price' => 850_000, 'cost' => 520_000, 'stock' => 2, 'min_stock' => 6],
            ['name' => 'بند مقاومتی', 'category' => 'equipment', 'price' => 640_000, 'cost' => 400_000, 'stock' => 15, 'min_stock' => 4],
        ];

        foreach ($products as $product) {
            Product::create($product + ['sku' => strtoupper(Str::random(6))]);
        }
    }

    /** @param Collection<int, Coach> $coaches */
    protected function seedClasses(Collection $coaches): void
    {
        $bookings = app(BookingService::class);

        $definitions = [
            [['fa' => 'پیلاتس', 'tr' => 'Pilates', 'en' => 'Pilates'], 'class', 16, 60, 900_000, $coaches[1], [0, 2, 4], '18:00'],
            [['fa' => 'یوگا', 'tr' => 'Yoga', 'en' => 'Yoga'], 'class', 20, 75, 800_000, $coaches[1], [1, 3], '09:00'],
            [['fa' => 'TRX', 'tr' => 'TRX', 'en' => 'TRX'], 'class', 12, 45, 1_000_000, $coaches[0], [1, 3, 5], '19:30'],
            [['fa' => 'سانس شنا', 'tr' => 'Yüzme Seansı', 'en' => 'Swim Session'], 'pool', 25, 90, 700_000, $coaches[2], [0, 1, 2, 3, 4, 5, 6], '16:00'],
        ];

        foreach ($definitions as [$name, $kind, $capacity, $minutes, $price, $coach, $weekdays, $time]) {
            $class = GymClass::create([
                'coach_id' => $coach->id,
                'name' => $name,
                'kind' => $kind,
                'capacity' => $capacity,
                'duration_minutes' => $minutes,
                'price' => $price,
                'color' => '#5EF38C',
            ]);

            $bookings->schedule(
                $class->id,
                $weekdays,
                $time,
                today()->subWeek()->toDateString(),
                today()->addWeeks(3)->toDateString(),
            );
        }
    }

    protected function seedMembers($tenant): void
    {
        $memberships = app(MembershipService::class);
        $invoices = app(InvoiceService::class);
        $plans = MembershipPlan::orderBy('sort_order')->get();

        $firstNames = ['علی', 'زهرا', 'محمد', 'فاطمه', 'حسین', 'مریم', 'امیر', 'نرگس', 'رضا', 'سمیرا', 'یاسین', 'الهام'];
        $lastNames = ['محمدی', 'حسینی', 'رضایی', 'کریمی', 'موسوی', 'احمدی', 'جعفری', 'صادقی'];

        foreach (range(1, 40) as $index) {
            $joined = now()->subDays(random_int(5, 300));

            $member = Member::create([
                'code' => (string) (1000 + $index),
                'first_name' => $firstNames[array_rand($firstNames)],
                'last_name' => $lastNames[array_rand($lastNames)],
                'phone' => '0913'.str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
                'gender' => random_int(0, 1) ? 'male' : 'female',
                'birth_date' => now()->subYears(random_int(18, 55))->subDays(random_int(0, 364)),
                'height' => random_int(155, 195),
                'weight' => random_int(52, 105),
                'blood_type' => ['A+', 'B+', 'O+', 'AB+', 'A-', 'O-'][array_rand(['A+', 'B+', 'O+', 'AB+', 'A-', 'O-'])],
                'joined_at' => $joined->toDateString(),
                'created_at' => $joined,
                'status' => 'active',
            ]);

            $membership = $memberships->sell($member, $plans->random(), [
                'starts_at' => $joined->toDateString(),
                'create_invoice' => true,
            ]);

            // Most people pay on the spot; a few leave a balance behind.
            if (random_int(1, 10) <= 8) {
                $invoice = $member->invoices()->latest()->first();

                if ($invoice) {
                    $invoices->pay($invoice, (float) $invoice->total, ['cash', 'card', 'transfer'][random_int(0, 2)]);
                }
            }

            $this->seedAttendance($member, $membership, $joined);
            $this->seedMeasurements($member);
        }

        // A handful of members who stopped coming, so the churn engine and
        // the win-back campaign have something real to find.
        Member::inRandomOrder()->limit(6)->get()->each(function (Member $member) {
            $member->attendances()->where('checked_in_at', '>=', now()->subDays(45))->delete();
        });
    }

    protected function seedAttendance(Member $member, $membership, $joined): void
    {
        $visits = random_int(4, 45);

        for ($i = 0; $i < $visits; $i++) {
            $at = $joined->copy()->addDays(random_int(0, max(1, (int) $joined->diffInDays(now()))))
                ->setTime(random_int(7, 21), [0, 15, 30, 45][random_int(0, 3)]);

            if ($at->isFuture()) {
                continue;
            }

            Attendance::create([
                'member_id' => $member->id,
                'membership_id' => $membership->id,
                'checked_in_at' => $at,
                'checked_out_at' => $at->copy()->addMinutes(random_int(45, 110)),
                'method' => ['qr', 'qr', 'qr', 'nfc', 'manual'][random_int(0, 4)],
                'consumed_session' => false,
            ]);
        }
    }

    protected function seedMeasurements(Member $member): void
    {
        $weight = (float) $member->weight;

        foreach (range(3, 0) as $monthsAgo) {
            $member->measurements()->create([
                'measured_at' => now()->subMonths($monthsAgo)->toDateString(),
                'weight' => round($weight + $monthsAgo * 0.8, 1),
                'height' => $member->height,
                'fat_percent' => round(18 + $monthsAgo * 0.9 + random_int(0, 40) / 10, 1),
                'muscle_mass' => round($weight * 0.42 - $monthsAgo * 0.3, 1),
                'waist' => round(80 + $monthsAgo * 1.2, 1),
                'arm' => round(33 + random_int(0, 40) / 10, 1),
            ]);
        }
    }
}
