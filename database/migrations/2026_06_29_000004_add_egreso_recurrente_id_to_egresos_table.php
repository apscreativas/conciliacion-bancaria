<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('egresos', function (Blueprint $table) {
            // Liga al egreso generado con su plantilla (Finanzas Fase 3). nullOnDelete:
            // borrar una plantilla NO borra los egresos ya generados (se conserva el histórico).
            $table->foreignId('egreso_recurrente_id')->nullable()->after('categoria_id')
                ->constrained('egresos_recurrentes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('egresos', function (Blueprint $table) {
            $table->dropForeign(['egreso_recurrente_id']);
            $table->dropColumn('egreso_recurrente_id');
        });
    }
};
