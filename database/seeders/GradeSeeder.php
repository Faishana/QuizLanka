<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Grade;

class GradeSeeder extends Seeder
{
    public function run(): void
    {
        $grades = [

            [
                'name' => 'Grade 5 Scholarship',
                'slug' => 'grade-5-scholarship',
                'category' => 'primary',
                'sort_order' => 1,
            ],

            [
                'name' => 'Grade 10',
                'slug' => 'grade-10',
                'category' => 'ol',
                'sort_order' => 2,
            ],

            [
                'name' => 'Grade 11',
                'slug' => 'grade-11',
                'category' => 'ol',
                'sort_order' => 3,
            ],

            [
                'name' => 'A/L Science',
                'slug' => 'al-science',
                'category' => 'al',
                'sort_order' => 4,
            ],

            [
                'name' => 'Government Exams',
                'slug' => 'government-exams',
                'category' => 'government',
                'sort_order' => 5,
            ],

        ];

        foreach ($grades as $grade) {
            Grade::create($grade);
        }
    }
}
