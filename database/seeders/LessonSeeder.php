<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Lesson;
use App\Models\Subject;

class LessonSeeder extends Seeder
{
    public function run(): void
    {
        $science = Subject::where('slug', 'science')->first();

        Lesson::create([
            'subject_id' => $science->id,
            'title' => 'Cells and Organisms',
            'slug' => 'cells-and-organisms',
        ]);

        Lesson::create([
            'subject_id' => $science->id,
            'title' => 'Human Digestive System',
            'slug' => 'human-digestive-system',
        ]);
    }
}
