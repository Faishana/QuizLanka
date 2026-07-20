<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Subject;
use App\Models\Grade;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $grade11 = Grade::where('slug', 'grade-11')->first();

        if (!$grade11) {
            $this->command->error('Grade 11 not found.');
            return;
        }

        $subjects = [

            [
                'name' => 'Science',
                'slug' => 'science',
                'color' => '#3B82F6',
            ],

            [
                'name' => 'Mathematics',
                'slug' => 'mathematics',
                'color' => '#10B981',
            ],

            [
                'name' => 'English',
                'slug' => 'english',
                'color' => '#F59E0B',
            ],

            [
                'name' => 'Sinhala',
                'slug' => 'sinhala',
                'color' => '#8B5CF6',
            ],

            [
                'name' => 'History',
                'slug' => 'history',
                'color' => '#EF4444',
            ],

            [
                'name' => 'Buddhism',
                'slug' => 'buddhism',
                'color' => '#F97316',
            ],

            [
                'name' => 'Geography',
                'slug' => 'geography',
                'color' => '#06B6D4',
            ],

            [
                'name' => 'Health & Physical Education',
                'slug' => 'health-physical-education',
                'color' => '#84CC16',
            ],

            [
                'name' => 'Information & Communication Technology',
                'slug' => 'ict',
                'color' => '#6366F1',
            ],

            [
                'name' => 'Civic Education',
                'slug' => 'civic-education',
                'color' => '#14B8A6',
            ],
        ];

        foreach ($subjects as $subject) {

            Subject::firstOrCreate(
                [
                    'grade_id' => $grade11->id,
                    'slug' => $subject['slug'],
                ],
                [
                    'name' => $subject['name'],
                    'color' => $subject['color'],
                ]
            );
        }

        $this->command->info('Grade 11 subjects seeded successfully.');
    }
}
