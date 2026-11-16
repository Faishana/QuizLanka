
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('grade_category', 30)
                ->nullable()
                ->after('slug')
                ->index();
        });

        // Backfill categories for existing subjects
        // that already have grade assignments.
        DB::table('subjects')
            ->orderBy('id')
            ->chunkById(100, function ($subjects) {
                foreach ($subjects as $subject) {
                    $category = DB::table('grade_subjects')
                        ->join(
                            'grades',
                            'grades.id',
                            '=',
                            'grade_subjects.grade_id'
                        )
                        ->where('grade_subjects.subject_id', $subject->id)
                        ->value('grades.category');

                    if ($category) {
                        DB::table('subjects')
                            ->where('id', $subject->id)
                            ->update([
                                'grade_category' => $category,
                            ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropIndex(['grade_category']);
            $table->dropColumn('grade_category');
        });
    }
};
