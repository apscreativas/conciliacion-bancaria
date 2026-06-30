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
        Schema::create('egresos_recurrentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();

            $table->string('descripcion');
            $table->string('proveedor')->nullable();
            $table->decimal('monto', 15, 2);

            $table->enum('frecuencia', ['quincenal', 'mensual', 'bimestral', 'trimestral', 'anual'])->default('mensual');
            $table->unsignedTinyInteger('dia_del_mes'); // 1–31 (clamp al mes)
            $table->enum('ajuste_dia_habil', ['ninguno', 'habil_anterior', 'habil_siguiente'])->default('habil_anterior');

            $table->date('fecha_inicio');
            $table->enum('vigencia_tipo', ['indefinida', 'hasta_fecha', 'num_pagos'])->default('indefinida');
            $table->date('fecha_fin')->nullable();
            $table->integer('num_pagos')->nullable();
            $table->integer('pagos_generados')->default(0);

            $table->boolean('activo')->default(true);
            $table->date('proxima_generacion');

            // nullOnDelete: borrar al usuario que la creó NO debe borrar la plantilla
            // (el team sigue generando ese fijo). La plantilla es un registro financiero.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'activo', 'proxima_generacion']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egresos_recurrentes');
    }
};
