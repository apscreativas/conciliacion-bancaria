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
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('nombre');
            $table->enum('tipo', ['ingreso', 'egreso']);
            $table->enum('grupo', ['ingreso', 'costo_venta', 'gasto_operativo', 'abajo_ebitda']);
            // naturaleza aplica sobre todo a egresos; los ingresos quedan en null
            $table->enum('naturaleza', ['fijo', 'variable'])->nullable();
            $table->boolean('activo')->default(true);
            $table->integer('orden')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'nombre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categorias');
    }
};
