<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumo_plan_lineas', function (Blueprint $table) {
            $table->decimal('dinero_solicitado', 14, 2)->nullable()->after('stock_debido');
        });

        Schema::table('consumo_planes', function (Blueprint $table) {
            $table->string('fecha_pedido', 50)->nullable()->after('tipo');
            $table->string('solicitado_por', 255)->nullable()->after('fecha_pedido');
            $table->string('autorizado_por', 255)->nullable()->after('solicitado_por');
            $table->string('cantidad_dinero_solicitada', 255)->nullable()->after('autorizado_por');
        });

        Schema::create('consumo_plan_pedidos_especiales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumo_plan_id')->constrained('consumo_planes')->cascadeOnDelete();
            $table->unsignedInteger('orden');
            $table->string('descripcion', 500);
            $table->decimal('cantidad', 14, 2)->nullable();
            $table->timestamps();

            $table->index(['consumo_plan_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumo_plan_pedidos_especiales');

        Schema::table('consumo_planes', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_pedido',
                'solicitado_por',
                'autorizado_por',
                'cantidad_dinero_solicitada',
            ]);
        });

        Schema::table('consumo_plan_lineas', function (Blueprint $table) {
            $table->dropColumn('dinero_solicitado');
        });
    }
};
