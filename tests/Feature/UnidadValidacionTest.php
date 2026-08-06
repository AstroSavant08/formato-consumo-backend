<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnidadValidacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticateApiUser();
    }

    private function createProducto(
        string $nombre = 'Producto unidad test',
        ?string $unidadDefault = 'UND',
    ): Producto {
        return Producto::query()->create([
            'nombre' => $nombre,
            'nombre_normalizado' => TextNormalizer::normalize($nombre),
            'unidad_default' => $unidadDefault,
            'stock_minimo_referencia' => 10,
            'activo' => true,
            'es_historico_excel' => false,
        ]);
    }

    private function createArea(string $codigo = 'MANTENIMIENTO'): Area
    {
        return Area::query()->create([
            'codigo' => TextNormalizer::normalize($codigo),
            'nombre' => $codigo,
            'activo' => true,
        ]);
    }

    private function createInventario(Producto $producto, float $stockFisico = 100): Inventario
    {
        return Inventario::query()->create([
            'producto_id' => $producto->id,
            'stock_fisico' => $stockFisico,
            'stock_reserva' => 0,
            'stock_comprometido' => 0,
            'stock_minimo' => 10,
        ]);
    }

    private function entregaPayload(Producto $producto, Area $area, string $unidad, float $cantidad = 5): array
    {
        return $this->withEntregaPersona([
            'fecha' => '2026-07-24',
            'producto_id' => $producto->id,
            'area_id' => $area->id,
            'cantidad' => $cantidad,
            'unidad' => $unidad,
            'quien_recibe' => 'Receptor unidad test',
            'entregado_por' => 'Entregador unidad test',
        ]);
    }

    public function test_entrada_acepta_coincidencia_exacta_de_unidad(): void
    {
        $producto = $this->createProducto(unidadDefault: 'UND');
        $this->createInventario($producto, 100);

        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [
            'cantidad' => 10,
            'unidad' => 'UND',
        ])
            ->assertCreated()
            ->assertJsonPath('data.stock_posterior', 110);

        $this->assertSame('110.00', Inventario::query()->firstOrFail()->stock_fisico);
        $this->assertSame(1, MovimientoInventario::count());
    }

    public function test_entrada_acepta_unidad_con_diferente_mayusculas(): void
    {
        $producto = $this->createProducto(unidadDefault: 'UND');
        $this->createInventario($producto, 100);

        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [
            'cantidad' => 10,
            'unidad' => ' und ',
        ])->assertCreated();

        $this->assertSame('110.00', Inventario::query()->firstOrFail()->stock_fisico);
    }

    public function test_entrada_rechaza_unidad_incompatible_sin_modificar_inventario(): void
    {
        $producto = $this->createProducto(unidadDefault: 'UND');
        $this->createInventario($producto, 100);

        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [
            'cantidad' => 10,
            'unidad' => 'ML',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La unidad de la operación no coincide con la unidad del producto.')
            ->assertJsonPath('data.unidad_producto', 'UND')
            ->assertJsonPath('data.unidad_recibida', 'ML');

        $this->assertSame('100.00', Inventario::query()->firstOrFail()->stock_fisico);
        $this->assertSame(0, MovimientoInventario::count());
    }

    public function test_producto_sin_unidad_default_permite_operacion_numerica_sin_validar_coincidencia(): void
    {
        $producto = $this->createProducto('Producto sin unidad base', null);
        $area = $this->createArea();
        $this->createInventario($producto, 60);

        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [
            'cantidad' => 5,
            'unidad' => 'ML',
        ])->assertCreated();

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 'KG', 10))
            ->assertCreated();

        $this->assertSame('55.00', Inventario::query()->firstOrFail()->stock_fisico);
        $this->assertSame(2, MovimientoInventario::count());
        $this->assertSame(1, Entrega::count());
    }

    public function test_entrega_operativa_valida_con_unidad_compatible_descuenta_inventario(): void
    {
        $producto = $this->createProducto(unidadDefault: 'UND');
        $area = $this->createArea();
        $this->createInventario($producto, 80);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 'und', 20))
            ->assertCreated()
            ->assertJsonPath('data.fuente', 'sistema');

        $entrega = Entrega::query()->firstOrFail();
        $movimiento = MovimientoInventario::query()->firstOrFail();

        $this->assertSame('entrega', $movimiento->tipo);
        $this->assertSame('80.00', $movimiento->stock_anterior);
        $this->assertSame('60.00', $movimiento->stock_posterior);
        $this->assertSame($entrega->id, $movimiento->referencia_id);
        $this->assertSame('60.00', Inventario::query()->firstOrFail()->stock_fisico);
    }

    public function test_entrega_operativa_invalida_no_crea_entrega_ni_movimiento_ni_descuento(): void
    {
        $producto = $this->createProducto(unidadDefault: 'UND');
        $area = $this->createArea();
        $this->createInventario($producto, 80);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 'ML', 20))
            ->assertStatus(422)
            ->assertJsonPath('message', 'La unidad de la operación no coincide con la unidad del producto.')
            ->assertJsonPath('data.unidad_producto', 'UND')
            ->assertJsonPath('data.unidad_recibida', 'ML');

        $this->assertSame(0, Entrega::count());
        $this->assertSame(0, MovimientoInventario::count());
        $this->assertSame('80.00', Inventario::query()->firstOrFail()->stock_fisico);
    }

    public function test_entregas_historicas_no_son_modificadas_ni_revalidadas(): void
    {
        $area = $this->createArea();
        $productoHistorico = $this->createProducto('Producto histórico unidad', 'ML');
        $stagingImportados = [];

        foreach ([59, 79, 89] as $filaExcel) {
            $staging = ExcelImportStaging::query()->create([
                'fila_excel' => $filaExcel,
                'fecha_raw' => '45330',
                'producto_raw' => 'PRODUCTO HIST',
                'cantidad_raw' => '10',
                'unidad_raw' => 'ml',
                'area_raw' => 'MANTENIMIENTO',
                'quien_recibe_raw' => 'Receptor histórico',
                'entrega_raw' => 'Entregador histórico',
                'estado' => 'importado',
                'excel_hash' => hash('sha256', "unidad-historico-{$filaExcel}"),
                'producto_id' => $productoHistorico->id,
                'area_id' => $area->id,
            ]);

            Entrega::query()->create([
                'fecha' => '2024-02-08',
                'area_id' => $area->id,
                'producto_id' => $productoHistorico->id,
                'cantidad' => 10,
                'unidad' => 'ml',
                'quien_recibe' => 'Receptor histórico',
                'entregado_por' => 'Entregador histórico',
                'fuente' => 'excel_historico',
                'staging_id' => $staging->id,
                'excel_fila' => $staging->fila_excel,
                'excel_hash' => $staging->excel_hash,
                'es_posible_duplicado' => false,
            ]);

            ExcelImportHomologacion::query()->create([
                'staging_id' => $staging->id,
                'producto_id_destino' => $productoHistorico->id,
                'confirmado_por' => 'Tester',
                'fecha_confirmacion' => now(),
                'notas' => 'Homologación intacta',
            ]);

            $stagingImportados[] = $staging;
        }

        $productoOperativo = $this->createProducto(unidadDefault: 'UND');
        $this->createInventario($productoOperativo, 50);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($productoOperativo, $area, 'ML', 5))
            ->assertStatus(422);

        $this->assertSame(3, Entrega::where('fuente', 'excel_historico')->count());
        $this->assertSame(3, ExcelImportHomologacion::count());
        $this->assertSame(3, ExcelImportStaging::where('estado', 'importado')->count());
        $this->assertSame('ml', Entrega::query()->where('staging_id', $stagingImportados[0]->id)->value('unidad'));
        $this->assertSame('ml', $stagingImportados[0]->fresh()->unidad_raw);
        $this->assertSame(0, Entrega::where('fuente', 'sistema')->count());
    }
}
