<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subject;
use App\Models\Grade;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $grade10 = Grade::where('slug', 'grade-10')->first();

        Subject::create([
            'grade_id' => $grade10->id,
            'name' => 'Science',
            'slug' => 'science',
            'color' => '#3B82F6',
        ]);

        Subject::create([
            'grade_id' => $grade10->id,
            'name' => 'Mathematics',
            'slug' => 'mathematics',
            'color' => '#10B981',
        ]);

        Subject::create([
            'grade_id' => $grade10->id,
            'name' => 'English',
            'slug' => 'english',
            'color' => '#F59E0B',
        ]);
    }
}
