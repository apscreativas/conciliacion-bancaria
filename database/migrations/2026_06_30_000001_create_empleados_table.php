<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();

            $table->string('nombre');
            $table->string('puesto')->nullable();
            $table->date('fecha_entrada');
            $table->date('fecha_baja')->nullable();
            $table->decimal('salario_fiscal', 15, 2); // mensual
            $table->decimal('salario_real', 15, 2);   // mensual
            $table->enum('clasificacion', ['tecnica', 'administrativa'])->nullable();
            $table->boolean('activo')->default(true);

            // nullOnDelete: borrar al usuario creador NO borra al empleado (registro financiero).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
