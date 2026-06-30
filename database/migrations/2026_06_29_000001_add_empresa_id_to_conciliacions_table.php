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
        Schema::table('conciliacions', function (Blueprint $table) {
            // Etiqueta de unidad de negocio (Finanzas Fase 1). Nullable = "sin asignar".
            // nullOnDelete: borrar una empresa NUNCA borra conciliaciones; solo limpia la etiqueta.
            $table->foreignId('empresa_id')->nullable()->after('group_id')
                ->constrained('empresas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conciliacions', function (Blueprint $table) {
            $table->dropForeign(['empresa_id']);
            $table->dropColumn('empresa_id');
        });
    }
};
