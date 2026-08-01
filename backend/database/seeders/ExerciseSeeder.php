<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;

/** The platform wide exercise library every club starts from. */
class ExerciseSeeder extends Seeder
{
    public function run(): void
    {
        $exercises = [
            ['chest', 'barbell', 'Bench Press', 'پرس سینه', 'Bench Press'],
            ['chest', 'dumbbell', 'Incline Dumbbell Press', 'پرس بالا سینه دمبل', 'Eğik Dumbbell Press'],
            ['chest', 'cable', 'Cable Fly', 'قفسه سیم‌کش', 'Cable Fly'],
            ['chest', 'bodyweight', 'Push Up', 'شنا سوئدی', 'Şınav'],
            ['back', 'machine', 'Lat Pulldown', 'زیربغل سیم‌کش', 'Lat Çekiş'],
            ['back', 'barbell', 'Barbell Row', 'پارویی هالتر', 'Barbell Kürek'],
            ['back', 'cable', 'Seated Cable Row', 'پارویی نشسته', 'Oturarak Kürek'],
            ['back', 'barbell', 'Deadlift', 'ددلیفت', 'Deadlift'],
            ['legs', 'barbell', 'Back Squat', 'اسکات', 'Squat'],
            ['legs', 'barbell', 'Romanian Deadlift', 'ددلیفت رومانیایی', 'Romen Deadlift'],
            ['legs', 'machine', 'Leg Press', 'پرس پا', 'Leg Press'],
            ['legs', 'machine', 'Leg Curl', 'پشت پا دستگاه', 'Leg Curl'],
            ['legs', 'machine', 'Standing Calf Raise', 'ساق پا ایستاده', 'Ayakta Calf'],
            ['shoulders', 'barbell', 'Overhead Press', 'پرس سرشانه', 'Omuz Press'],
            ['shoulders', 'dumbbell', 'Lateral Raise', 'نشر جانب', 'Yan Kaldırış'],
            ['shoulders', 'dumbbell', 'Rear Delt Fly', 'نشر خم', 'Arka Omuz'],
            ['shoulders', 'barbell', 'Shrug', 'شراگ', 'Shrug'],
            ['arms', 'barbell', 'Barbell Curl', 'جلو بازو هالتر', 'Barbell Curl'],
            ['arms', 'dumbbell', 'Hammer Curl', 'جلو بازو چکشی', 'Çekiç Curl'],
            ['arms', 'cable', 'Triceps Pushdown', 'پشت بازو سیم‌کش', 'Triceps Pushdown'],
            ['arms', 'dumbbell', 'Overhead Triceps Extension', 'پشت بازو بالای سر', 'Baş Üstü Triceps'],
            ['core', 'bodyweight', 'Plank', 'پلانک', 'Plank'],
            ['core', 'bodyweight', 'Crunch', 'کرانچ', 'Mekik'],
            ['core', 'cable', 'Cable Woodchop', 'چرخش سیم‌کش', 'Cable Woodchop'],
            ['cardio', 'machine', 'Treadmill', 'تردمیل', 'Koşu Bandı'],
            ['cardio', 'machine', 'Rowing Machine', 'دستگاه پارو', 'Kürek Makinesi'],
            ['cardio', 'bodyweight', 'Burpee', 'برپی', 'Burpee'],
        ];

        // The platform library is owned by no club, so it is safe to rebuild
        // it wholesale on every seed run.
        Exercise::whereNull('tenant_id')->delete();

        foreach ($exercises as [$group, $equipment, $en, $fa, $tr]) {
            Exercise::create([
                'tenant_id' => null,
                'muscle_group' => $group,
                'equipment' => $equipment,
                'name' => ['en' => $en, 'fa' => $fa, 'tr' => $tr],
            ]);
        }
    }
}
