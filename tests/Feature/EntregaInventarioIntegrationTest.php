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

class EntregaInventarioIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticateApiUser();
    }

    private function createProducto(string $nombre = 'Producto entrega operativa'): Producto
    {
        return Producto::query()->create([
            'nombre' => $nombre,
            'nombre_normalizado' => TextNormalizer::normalize($nombre),
            'unidad_default' => 'UND',
            'stock_minimo_referencia' => 20,
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

    private function createInventario(
        Producto $producto,
        float $stockFisico = 80,
        float $stockReserva = 0,
        float $stockComprometido = 0,
        float $stockMinimo = 20,
    ): Inventario {
        return Inventario::query()->create([
            'producto_id' => $producto->id,
            'stock_fisico' => $stockFisico,
            'stock_reserva' => $stockReserva,
            'stock_comprometido' => $stockComprometido,
            'stock_minimo' => $stockMinimo,
        ]);
    }

    private function entregaPayload(Producto $producto, Area $area, float $cantidad = 20): array
    {
        return [
            'fecha' => '2026-07-21',
            'producto_id' => $producto->id,
            'area_id' => $area->id,
            'cantidad' => $cantidad,
            'unidad' => 'UND',
            'quien_recibe' => 'Receptor operativo',
            'entregado_por' => 'Entregador operativo',
        ];
    }

    public function test_entrega_operativa_con_stock_suficiente_descuenta_inventario(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea();
        $this->createInventario($producto, 80);

        $response = $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 20));

        $response->assertCreated()
            ->assertJsonPath('data.fuente', 'sistema')
            ->assertJsonPath('data.cantidad', '20.00');

        $entrega = Entrega::query()->firstOrFail();
        $movimiento = MovimientoInventario::query()->firstOrFail();
        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();

        $this->assertSame('sistema', $entrega->fuente);
        $this->assertSame('entrega', $movimiento->tipo);
        $this->assertSame('80.00', $movimiento->stock_anterior);
        $this->assertSame('60.00', $movimiento->stock_posterior);
        $this->assertSame('20.00', $movimiento->cantidad);
        $this->assertSame('Entrega', $movimiento->referencia_tipo);
        $this->assertSame($entrega->id, $movimiento->referencia_id);
        $this->assertSame('60.00', $inventario->stock_fisico);
    }

    public function test_entrega_operativa_rechaza_stock_insuficiente_sin_cambios(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea();
        $this->createInventario($producto, 10);

        $response = $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 15));

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Stock insuficiente para realizar la entrega.')
            ->assertJsonPath('data.stock_disponible', 10)
            ->assertJsonPath('data.cantidad_solicitada', 15);

        $this->assertSame(0, Entrega::count());
        $this->assertSame(0, MovimientoInventario::count());
        $this->assertSame('10.00', Inventario::query()->firstOrFail()->stock_fisico);
    }

    public function test_entrega_operativa_respeta_stock_reserva_y_comprometido(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea();
        $this->createInventario($producto, 100, 30, 20);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 51))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stock insuficiente para realizar la entrega.')
            ->assertJsonPath('data.stock_disponible', 50)
            ->assertJsonPath('data.cantidad_solicitada', 51);

        $this->assertSame(0, Entrega::count());
        $this->assertSame(0, MovimientoInventario::count());
        $this->assertSame('100.00', Inventario::query()->firstOrFail()->stock_fisico);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 50))
            ->assertCreated();

        $this->assertSame(1, Entrega::count());
        $this->assertSame(1, MovimientoInventario::count());
        $this->assertSame('50.00', Inventario::query()->firstOrFail()->stock_fisico);
    }

    public function test_atomicidad_revierte_entrega_movimiento_e_inventario_ante_fallo(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea();
        $this->createInventario($producto, 80);

        MovimientoInventario::creating(function () {
            throw new \RuntimeException('Fallo crítico simulado durante movimiento de entrega operativa.');
        });

        try {
            $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 20))
                ->assertStatus(500);
        } finally {
            MovimientoInventario::flushEventListeners();
        }

        $this->assertSame(0, Entrega::count());
        $this->assertSame(0, MovimientoInventario::count());
        $this->assertSame('80.00', Inventario::query()->firstOrFail()->stock_fisico);
    }

    public function test_entregas_excel_historico_no_generan_movimientos_ni_descuentos(): void
    {
        $area = $this->createArea();
        $productoHistorico = $this->createProducto('Producto histórico bloque 5.4');

        $staging = ExcelImportStaging::query()->create([
            'fila_excel' => 59,
            'fecha_raw' => '45330',
            'producto_raw' => 'PRODUCTO HIST',
            'cantidad_raw' => '10',
            'unidad_raw' => 'UND',
            'area_raw' => 'MANTENIMIENTO',
            'quien_recibe_raw' => 'Receptor',
            'entrega_raw' => 'Entregador',
            'estado' => 'importado',
            'excel_hash' => hash('sha256', 'bloque54-historico-59'),
            'producto_id' => $productoHistorico->id,
            'area_id' => $area->id,
        ]);

        Entrega::query()->create([
            'fecha' => '2024-02-08',
            'area_id' => $area->id,
            'producto_id' => $productoHistorico->id,
            'cantidad' => 10,
            'unidad' => 'UND',
            'quien_recibe' => 'Receptor',
            'entregado_por' => 'Entregador',
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

        $this->assertSame(0, Inventario::count());
        $this->assertSame(0, MovimientoInventario::count());
        $this->assertSame(1, Entrega::where('fuente', 'excel_historico')->count());
        $this->assertSame(1, ExcelImportHomologacion::count());
        $this->assertSame('importado', ExcelImportStaging::query()->find($staging->id)->estado);
    }

    public function test_entregas_operativas_existentes_no_se_reprocesan_retroactivamente(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea();

        foreach (range(1, 4) as $numero) {
            Entrega::query()->create([
                'fecha' => '2026-07-01',
                'area_id' => $area->id,
                'producto_id' => $producto->id,
                'cantidad' => $numero,
                'unidad' => 'UND',
                'quien_recibe' => "Receptor {$numero}",
                'entregado_por' => "Entregador {$numero}",
                'fuente' => 'sistema',
            ]);
        }

        $this->assertSame(0, MovimientoInventario::count());

        $this->createInventario($producto, 80);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 5))
            ->assertCreated();

        $this->assertSame(5, Entrega::count());
        $this->assertSame(1, MovimientoInventario::count());

        $movimiento = MovimientoInventario::query()->firstOrFail();
        $this->assertSame(5, $movimiento->referencia_id);
        $this->assertSame('Entrega', $movimiento->referencia_tipo);

        $this->assertSame(
            0,
            MovimientoInventario::query()
                ->whereIn('referencia_id', [1, 2, 3, 4])
                ->count()
        );
    }
}
