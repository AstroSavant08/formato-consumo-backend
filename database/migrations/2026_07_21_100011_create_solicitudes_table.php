<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->foreignId('area_id')->constrained('areas');
            $table->foreignId('usuario_id')->constrained('users');
            $table->date('fecha');
            $table->enum('estado', [
                'pendiente',
                'en_revision',
                'aprobada',
                'rechazada',
                'entregada',
                'cancelada',
            ])->default('pendiente');
            $table->text('justificacion')->nullable();
            $table->text('observaciones')->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes');
    }
};
