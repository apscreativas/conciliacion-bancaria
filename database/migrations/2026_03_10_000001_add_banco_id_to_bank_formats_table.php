<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_formats', function (Blueprint $table) {
            $table->foreignId('banco_id')->nullable()->after('team_id')->constrained('bancos')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('bank_formats', function (Blueprint $table) {
            $table->dropForeign(['banco_id']);
            $table->dropColumn('banco_id');
        });
    }
};
