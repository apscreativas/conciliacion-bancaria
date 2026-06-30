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
        Schema::create('ingresos_manuales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Etiquetas de clasificación. nullOnDelete: borrar empresa/categoría NUNCA borra el ingreso.
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();

            $table->date('fecha');
            $table->decimal('monto', 15, 2);
            $table->string('descripcion');
            $table->string('cliente')->nullable();
            $table->enum('metodo', ['efectivo', 'otro'])->default('efectivo');

            // nullOnDelete: un registro financiero sobrevive al borrado de su creador (criterio Fase 3B).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // quién lo registró
            $table->timestamps();

            $table->index(['team_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingresos_manuales');
    }
};
