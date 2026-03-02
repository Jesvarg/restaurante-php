<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar columnas para razones de rechazo/suspensión.
        Schema::table('restaurants', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('status');
            $table->text('suspension_reason')->nullable()->after('rejection_reason');
        });

        // Normalizar estado heredado.
        DB::table('restaurants')
            ->where('status', 'active')
            ->update(['status' => 'approved']);
    }

    public function down(): void
    {
        DB::table('restaurants')
            ->where('status', 'approved')
            ->update(['status' => 'active']);

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['rejection_reason', 'suspension_reason']);
        });
    }
};
