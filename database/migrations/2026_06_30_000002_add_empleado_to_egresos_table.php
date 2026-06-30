<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('egresos', function (Blueprint $table) {
            $table->foreignId('empleado_id')->nullable()->after('egreso_recurrente_id')
                ->constrained('empleados')->nullOnDelete();

            // Discriminador de nómina: desacopla la idempotencia de la categoría (mutable
            // por clasificacion). NULL para egresos manuales/recurrentes.
            $table->enum('concepto_nomina', ['fiscal', 'complemento'])->nullable()->after('origen');

            // Idempotencia en DB: un egreso de nómina por (empleado, fecha, concepto).
            // NULLs múltiples permitidos → no afecta egresos manuales/recurrentes.
            $table->unique(['empleado_id', 'fecha', 'concepto_nomina'], 'egresos_empleado_periodo_unique');
        });

        // user_id pasa a nullable + nullOnDelete: el generador puede insertar user_id null
        // (empleado.user_id es nullOnDelete) y un registro financiero debe sobrevivir al
        // borrado de su creador. Cierra el hallazgo #9 de Fase 3.
        Schema::table('egresos', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('egresos', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });
        Schema::table('egresos', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('egresos', function (Blueprint $table) {
            $table->dropUnique('egresos_empleado_periodo_unique');
            $table->dropForeign(['empleado_id']);
            $table->dropColumn(['empleado_id', 'concepto_nomina']);
        });

        // Best-effort: restaurar user_id a cascadeOnDelete (no NOT NULL si hay filas null).
        Schema::table('egresos', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('egresos', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
