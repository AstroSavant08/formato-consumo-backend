<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producto_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->string('alias');
            $table->string('alias_normalizado')->index();
            $table->string('fuente', 30)->default('excel');
            $table->decimal('confianza', 3, 2)->default(0);
            $table->boolean('revisado')->default(false);
            $table->boolean('requiere_revision')->default(false);
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->unique(['alias_normalizado', 'fuente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_aliases');
    }
};
