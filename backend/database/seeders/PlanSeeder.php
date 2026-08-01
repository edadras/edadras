<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/** The SaaS tiers clubs subscribe to. */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'free',
                'name' => ['fa' => 'رایگان', 'tr' => 'Ücretsiz', 'en' => 'Free'],
                'description' => [
                    'fa' => 'برای شروع؛ تا ۵۰ عضو.',
                    'tr' => 'Başlangıç için; 50 üyeye kadar.',
                    'en' => 'To get started; up to 50 members.',
                ],
                'price' => 0,
                'period' => 'monthly',
                'trial_days' => 0,
                'max_members' => 50,
                'max_staff' => 2,
                'features' => ['members', 'memberships', 'attendance', 'reports_basic'],
                'sort_order' => 0,
            ],
            [
                'slug' => 'basic',
                'name' => ['fa' => 'پایه', 'tr' => 'Temel', 'en' => 'Basic'],
                'price' => 1_900_000,
                'period' => 'monthly',
                'trial_days' => 14,
                'max_members' => 300,
                'max_staff' => 5,
                'features' => ['members', 'memberships', 'attendance', 'classes', 'finance', 'shop', 'reports_basic'],
                'sort_order' => 1,
            ],
            [
                'slug' => 'professional',
                'name' => ['fa' => 'حرفه‌ای', 'tr' => 'Profesyonel', 'en' => 'Professional'],
                'price' => 4_900_000,
                'period' => 'monthly',
                'trial_days' => 14,
                'max_members' => 1500,
                'max_staff' => 20,
                'features' => [
                    'members', 'memberships', 'attendance', 'classes', 'finance', 'shop',
                    'inventory', 'crm', 'chat', 'wallet', 'reports_full', 'ai_insights',
                ],
                'sort_order' => 2,
            ],
            [
                'slug' => 'enterprise',
                'name' => ['fa' => 'سازمانی', 'tr' => 'Kurumsal', 'en' => 'Enterprise'],
                'price' => 12_900_000,
                'period' => 'monthly',
                'trial_days' => 30,
                'max_members' => null,
                'max_staff' => null,
                'max_branches' => 20,
                'features' => [
                    'members', 'memberships', 'attendance', 'classes', 'finance', 'shop',
                    'inventory', 'crm', 'chat', 'wallet', 'reports_full', 'ai_insights',
                    'ai_assistant', 'ai_programs', 'api_access', 'white_label', 'priority_support',
                ],
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
