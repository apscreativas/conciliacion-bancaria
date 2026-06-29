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
        Schema::create('egresos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Etiquetas de clasificación. nullOnDelete: borrar empresa/categoría NUNCA borra el egreso.
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();

            $table->date('fecha');
            $table->decimal('monto', 15, 2);
            $table->string('descripcion');
            $table->string('proveedor')->nullable();
            $table->enum('metodo_pago', ['transferencia', 'efectivo', 'tarjeta', 'otro'])->nullable();
            $table->string('comprobante_path')->nullable(); // futuro: adjuntar PDF/XML
            $table->enum('origen', ['manual', 'recurrente'])->default('manual');

            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // quién lo registró
            $table->timestamps();

            $table->index(['team_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('egresos');
    }
};
