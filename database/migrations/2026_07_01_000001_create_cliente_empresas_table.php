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
        Schema::create('cliente_empresas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Identidad estable del cliente = rfc de la factura. nombre solo display (último visto).
            $table->string('rfc');
            $table->string('nombre');

            // Mapeo editable/auto-aprendido. nullOnDelete: borrar empresa NO borra el mapeo.
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();

            $table->unsignedInteger('veces')->default(0); // cuántas veces se ha asignado (aprendizaje)
            $table->timestamp('ultima_asignacion_at')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // quién asignó por última vez
            $table->timestamps();

            // Un solo mapeo por rfc dentro del team.
            $table->unique(['team_id', 'rfc']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cliente_empresas');
    }
};
