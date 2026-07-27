<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportStaging;
use App\Models\Inventario;
use App\Models\Producto;
use App\Models\Solicitud;
use App\Models\User;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private function createProducto(string $nombre = 'Producto dashboard'): Producto
    {
        return Producto::query()->create([
            'nombre' => $nombre,
            'nombre_normalizado' => TextNormalizer::normalize($nombre),
            'unidad_default' => 'UND',
            'stock_minimo_referencia' => 10,
            'activo' => true,
            'es_historico_excel' => false,
        ]);
    }

    private function createArea(string $nombre = 'Area dashboard'): Area
    {
        return Area::query()->create([
            'codigo' => TextNormalizer::normalize($nombre),
            'nombre' => $nombre,
            'activo' => true,
        ]);
    }

    public function test_get_dashboard_resumen_returns_structure(): void
    {
        $this->getJson('/api/v1/dashboard/resumen?anio=2026&mes=7')
            ->assertOk()
            ->assertJsonPath('data.periodo.anio', 2026)
            ->assertJsonPath('data.periodo.mes', 7)
            ->assertJsonStructure([
                'data' => [
                    'periodo',
                    'alertas' => ['activas_total', 'por_severidad', 'por_tipo', 'recientes'],
                    'solicitudes' => ['total', 'pendientes_accion', 'por_estado', 'recientes'],
                    'inventario' => ['total_configurados', 'bajo_minimo_total', 'bajo_minimo'],
                    'entregas' => ['mes_total', 'por_fuente', 'por_area'],
                    'homologacion' => ['staging_total', 'por_estado'],
                    'generado_at',
                ],
            ]);
    }

    public function test_get_dashboard_resumen_aggregates_data(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea();

        Inventario::query()->create([
            'producto_id' => $producto->id,
            'stock_fisico' => 5,
            'stock_reserva' => 0,
            'stock_minimo' => 10,
            'stock_comprometido' => 0,
        ]);

        Alerta::query()->create([
            'tipo' => 'stock_minimo',
            'severidad' => 'amarillo',
            'producto_id' => $producto->id,
            'area_id' => null,
            'mensaje' => 'Stock bajo',
            'metadata' => [],
            'leida' => false,
        ]);

        Solicitud::query()->create([
            'numero' => 'SOL-2026-000001',
            'area_id' => $area->id,
            'usuario_id' => User::factory()->create()->id,
            'fecha' => '2026-07-10',
            'estado' => Solicitud::ESTADO_EN_REVISION,
            'justificacion' => 'Prueba dashboard',
            'total' => 100,
        ]);

        Entrega::query()->create([
            'fecha' => '2026-07-15',
            'area_id' => $area->id,
            'producto_id' => $producto->id,
            'cantidad' => 2,
            'unidad' => 'UND',
            'fuente' => 'sistema',
        ]);

        ExcelImportStaging::query()->create([
            'excel_hash' => 'hash-dashboard-1',
            'fila_excel' => 10,
            'fecha_raw' => '2026-07-01',
            'producto_raw' => 'Producto raw',
            'area_raw' => 'Area raw',
            'cantidad_raw' => '1',
            'estado' => 'pendiente',
        ]);

        $this->getJson('/api/v1/dashboard/resumen?anio=2026&mes=7')
            ->assertOk()
            ->assertJsonPath('data.alertas.activas_total', 1)
            ->assertJsonPath('data.solicitudes.pendientes_accion', 1)
            ->assertJsonPath('data.inventario.bajo_minimo_total', 1)
            ->assertJsonPath('data.entregas.mes_total', 1)
            ->assertJsonPath('data.homologacion.staging_total', 1);
    }

    public function test_get_dashboard_resumen_validates_mes(): void
    {
        $this->getJson('/api/v1/dashboard/resumen?mes=13')
            ->assertStatus(422);
    }
}
