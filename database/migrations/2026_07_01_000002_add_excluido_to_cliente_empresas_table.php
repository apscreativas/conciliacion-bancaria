<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliente_empresas', function (Blueprint $table) {
            // Exclusión del catálogo: este cliente "respeta etiquetas individuales" —
            // no aprende (recordar lo salta), no sugiere (bloqueante en la regla
            // estricta) y no se aplica en aplicarASinEmpresa. La asignación manual
            // por grupo en el historial sigue funcionando normal. El empresa_id
            // existente se conserva inerte (reversible al des-excluir).
            $table->boolean('excluido')->default(false)->after('empresa_id');
        });
    }

    public function down(): void
    {
        Schema::table('cliente_empresas', function (Blueprint $table) {
            $table->dropColumn('excluido');
        });
    }
};
