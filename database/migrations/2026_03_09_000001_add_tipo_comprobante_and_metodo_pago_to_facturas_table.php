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
        Schema::table('facturas', function (Blueprint $table) {
            $table->string('tipo_comprobante', 2)->nullable()->after('uuid');
            $table->string('metodo_pago', 3)->nullable()->after('tipo_comprobante');

            $table->index('tipo_comprobante');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropIndex(['tipo_comprobante']);
            $table->dropColumn(['tipo_comprobante', 'metodo_pago']);
        });
    }
};
