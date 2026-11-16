
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

public function up()
{
    Schema::table('subjects', function (Blueprint $table) {
        $table->dropForeign(['grade_id']);
        $table->dropColumn('grade_id');
    });
}

public function down()
{
    Schema::table('subjects', function (Blueprint $table) {
        $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
    });
}
