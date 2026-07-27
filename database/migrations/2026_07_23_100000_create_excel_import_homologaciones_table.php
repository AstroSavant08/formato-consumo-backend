<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_import_homologaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staging_id')
                ->unique()
                ->constrained('excel_import_staging')
                ->cascadeOnDelete();
            $table->foreignId('producto_id_destino')
                ->constrained('productos')
                ->restrictOnDelete();
            $table->string('confirmado_por')->nullable();
            $table->timestamp('fecha_confirmacion');
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_import_homologaciones');
    }
};
