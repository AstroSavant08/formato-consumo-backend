<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entregas', function (Blueprint $table) {
            $table->string('quien_retira_cedula', 20)->nullable()->after('entregado_por');
            $table->string('quien_retira_nombre', 255)->nullable()->after('quien_retira_cedula');
            $table->foreignId('persona_retira_id')->nullable()->after('quien_retira_nombre')
                ->constrained('personas')->nullOnDelete();
            $table->foreignId('registrado_por_user_id')->nullable()->after('persona_retira_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entregas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('persona_retira_id');
            $table->dropConstrainedForeignId('registrado_por_user_id');
            $table->dropColumn(['quien_retira_cedula', 'quien_retira_nombre']);
        });
    }
};
