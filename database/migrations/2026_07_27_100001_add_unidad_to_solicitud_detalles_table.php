<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitud_detalles', function (Blueprint $table) {
            $table->string('unidad', 20)->default('UND')->after('cantidad_solicitada');
        });
    }

    public function down(): void
    {
        Schema::table('solicitud_detalles', function (Blueprint $table) {
            $table->dropColumn('unidad');
        });
    }
};
