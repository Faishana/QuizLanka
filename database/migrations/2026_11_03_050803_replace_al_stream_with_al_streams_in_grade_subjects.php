<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add the new JSON column.
        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->json('al_streams')->nullable()->after('subject_id');
        });

        // Copy existing stream values into JSON arrays.
        DB::table('grade_subjects')
            ->whereNotNull('al_stream')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('grade_subjects')
                        ->where('id', $row->id)
                        ->update([
                            'al_streams' => json_encode([$row->al_stream]),
                        ]);
                }
            });

        // Remove the old column.
        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->dropColumn('al_stream');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore the old column.
        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->string('al_stream', 30)->nullable()->after('subject_id');
        });

        // Restore single-stream values where possible.
        DB::table('grade_subjects')
            ->whereNotNull('al_streams')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $streams = json_decode($row->al_streams, true);

                    DB::table('grade_subjects')
                        ->where('id', $row->id)
                        ->update([
                            'al_stream' => is_array($streams) && count($streams) === 1
                                ? $streams[0]
                                : null,
                        ]);
                }
            });

        // Remove the JSON column.
        Schema::table('grade_subjects', function (Blueprint $table) {
            $table->dropColumn('al_streams');
        });
    }
};
