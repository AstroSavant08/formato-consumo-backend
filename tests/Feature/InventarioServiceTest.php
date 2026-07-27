<?php

namespace Tests\Feature;

use App\Exceptions\InventarioException;
use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportStaging;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Services\InventarioService;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventarioServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createProducto(
        string $nombre = 'Producto inventario test',
        ?float $stockMinimoReferencia = null,
    ): Producto {
        return Producto::query()->create([
            'nombre' => $nombre,
            'nombre_normalizado' => TextNormalizer::normalize($nombre),
            'unidad_default' => 'UND',
            'stock_minimo_referencia' => $stockMinimoReferencia,
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

    public function test_crear_inventario_inicial(): void
    {
        $producto = $this->createProducto('Jabon operativo', 5);

        $inventario = app(InventarioService::class)->crearInventarioInicial($producto->id, 100, 10);

        $this->assertSame($producto->id, $inventario->producto_id);
        $this->assertSame('100.00', $inventario->stock_fisico);
        $this->assertSame('0.00', $inventario->stock_reserva);
        $this->assertSame('0.00', $inventario->stock_comprometido);
        $this->assertSame('10.00', $inventario->stock_minimo);
        $this->assertSame(100.0, $inventario->stock_disponible);
        $this->assertSame(0, MovimientoInventario::count());
    }

    public function test_crear_inventario_inicial_usa_stock_minimo_referencia_del_producto(): void
    {
        $producto = $this->createProducto('Producto con minimo referencia', 12.5);

        $inventario = app(InventarioService::class)->crearInventarioInicial($producto->id);

        $this->assertSame('0.00', $inventario->stock_fisico);
        $this->assertSame('12.50', $inventario->stock_minimo);
    }

    public function test_impide_inventario_duplicado(): void
    {
        $producto = $this->createProducto();

        app(InventarioService::class)->crearInventarioInicial($producto->id, 10);

        $this->expectException(InventarioException::class);
        $this->expectExceptionMessage('Ya existe inventario para este producto.');

        app(InventarioService::class)->crearInventarioInicial($producto->id, 20);
    }

    public function test_registrar_entrada_incrementa_stock_y_crea_movimiento(): void
    {
        $producto = $this->createProducto();
        app(InventarioService::class)->crearInventarioInicial($producto->id, 100);

        $movimiento = app(InventarioService::class)->registrarEntrada($producto->id, 50, 'compra', 1, 'Compra inicial');

        $inventario = Inventario::query()->where('producto_id', $producto->id)->first();

        $this->assertSame('150.00', $inventario->stock_fisico);
        $this->assertSame('entrada', $movimiento->tipo);
        $this->assertSame('50.00', $movimiento->cantidad);
        $this->assertSame('100.00', $movimiento->stock_anterior);
        $this->assertSame('150.00', $movimiento->stock_posterior);
        $this->assertSame('compra', $movimiento->referencia_tipo);
        $this->assertSame(1, $movimiento->referencia_id);
    }

    public function test_rechaza_entrada_cero(): void
    {
        $producto = $this->createProducto();
        app(InventarioService::class)->crearInventarioInicial($producto->id, 100);

        $this->expectException(InventarioException::class);
        $this->expectExceptionMessage('La cantidad de entrada debe ser mayor que cero.');

        app(InventarioService::class)->registrarEntrada($producto->id, 0);
    }

    public function test_rechaza_entrada_negativa(): void
    {
        $producto = $this->createProducto();
        app(InventarioService::class)->crearInventarioInicial($producto->id, 100);

        $this->expectException(InventarioException::class);

        app(InventarioService::class)->registrarEntrada($producto->id, -10);
    }

    public function test_ajuste_positivo(): void
    {
        $producto = $this->createProducto();
        app(InventarioService::class)->crearInventarioInicial($producto->id, 100);

        $movimiento = app(InventarioService::class)->registrarAjuste(
            $producto->id,
            150,
            'Ajuste por inventario físico',
        );

        $inventario = Inventario::query()->where('producto_id', $producto->id)->first();

        $this->assertSame('150.00', $inventario->stock_fisico);
        $this->assertSame('ajuste', $movimiento->tipo);
        $this->assertSame('50.00', $movimiento->cantidad);
        $this->assertSame('100.00', $movimiento->stock_anterior);
        $this->assertSame('150.00', $movimiento->stock_posterior);
    }

    public function test_ajuste_negativo(): void
    {
        $producto = $this->createProducto();
        app(InventarioService::class)->crearInventarioInicial($producto->id, 100);

        $movimiento = app(InventarioService::class)->registrarAjuste(
            $producto->id,
            80,
            'Corrección por conteo físico',
        );

        $inventario = Inventario::query()->where('producto_id', $producto->id)->first();

        $this->assertSame('80.00', $inventario->stock_fisico);
        $this->assertSame('ajuste', $movimiento->tipo);
        $this->assertSame('20.00', $movimiento->cantidad);
        $this->assertSame('100.00', $movimiento->stock_anterior);
        $this->assertSame('80.00', $movimiento->stock_posterior);
    }

    public function test_rechaza_ajuste_con_nuevo_stock_negativo(): void
    {
        $producto = $this->createProducto();
        app(InventarioService::class)->crearInventarioInicial($producto->id, 100);

        try {
            app(InventarioService::class)->registrarAjuste($producto->id, -1, 'Intento inválido');
            $this->fail('Se esperaba una excepción por stock negativo.');
        } catch (InventarioException $exception) {
            $this->assertStringContainsString('negativo', strtolower($exception->getMessage()));
        }

        $inventario = Inventario::query()->where('producto_id', $producto->id)->first();
        $this->assertSame('100.00', $inventario->stock_fisico);
        $this->assertSame(0, MovimientoInventario::count());
    }

    public function test_rechaza_ajuste_sin_observaciones(): void
    {
        $producto = $this->createProducto();
        app(InventarioService::class)->crearInventarioInicial($producto->id, 100);

        $this->expectException(InventarioException::class);
        $this->expectExceptionMessage('La observación es obligatoria');

        app(InventarioService::class)->registrarAjuste($producto->id, 90, '   ');
    }

    public function test_atomicidad_revierte_inventario_y_movimiento_ante_fallo(): void
    {
        $producto = $this->createProducto();
        app(InventarioService::class)->crearInventarioInicial($producto->id, 100);

        MovimientoInventario::creating(function () {
            throw new \RuntimeException('Fallo crítico simulado durante movimiento de inventario.');
        });

        try {
            app(InventarioService::class)->registrarEntrada($producto->id, 50);
            $this->fail('Se esperaba una excepción crítica.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Fallo crítico simulado', $exception->getMessage());
        } finally {
            MovimientoInventario::flushEventListeners();
        }

        $inventario = Inventario::query()->where('producto_id', $producto->id)->first();
        $this->assertSame('100.00', $inventario->stock_fisico);
        $this->assertSame(0, MovimientoInventario::count());
    }

    public function test_stock_disponible_considera_reserva_y_comprometido(): void
    {
        $producto = $this->createProducto();
        $inventario = app(InventarioService::class)->crearInventarioInicial($producto->id, 100);
        $inventario->update([
            'stock_reserva' => 20,
            'stock_comprometido' => 10,
        ]);

        $consulta = app(InventarioService::class)->obtenerPorProducto($producto->id);

        $this->assertNotNull($consulta);
        $this->assertSame(100.0, $consulta['stock_fisico']);
        $this->assertSame(20.0, $consulta['stock_reserva']);
        $this->assertSame(10.0, $consulta['stock_comprometido']);
        $this->assertSame(70.0, $consulta['stock_disponible']);
    }

    public function test_obtener_por_producto_es_solo_lectura(): void
    {
        $producto = $this->createProducto();
        app(InventarioService::class)->crearInventarioInicial($producto->id, 25);

        $before = Inventario::query()->where('producto_id', $producto->id)->first()->updated_at;

        app(InventarioService::class)->obtenerPorProducto($producto->id);

        $after = Inventario::query()->where('producto_id', $producto->id)->first()->updated_at;
        $this->assertTrue($before->equalTo($after));
    }

    public function test_relaciones_de_producto_inventario_movimientos_y_alertas(): void
    {
        $producto = $this->createProducto('Producto relaciones');

        $this->assertInstanceOf(Inventario::class, $producto->inventario()->getRelated());
        $this->assertInstanceOf(MovimientoInventario::class, $producto->movimientosInventario()->getRelated());
        $this->assertInstanceOf(\App\Models\Alerta::class, $producto->alertas()->getRelated());
    }

    public function test_historicos_sin_impacto_de_inventario_service(): void
    {
        $area = $this->createArea();
        $productoHistorico = $this->createProducto('Producto histórico promovido');

        $stagingIds = [];
        foreach ([61, 81, 91] as $filaExcel) {
            $staging = ExcelImportStaging::query()->create([
                'fila_excel' => $filaExcel,
                'fecha_raw' => '45330',
                'producto_raw' => 'PRODUCTO HIST',
                'cantidad_raw' => '10',
                'unidad_raw' => 'UND',
                'area_raw' => 'MANTENIMIENTO',
                'quien_recibe_raw' => 'Receptor',
                'entrega_raw' => 'Entregador',
                'estado' => 'importado',
                'excel_hash' => hash('sha256', "historico-{$filaExcel}"),
                'producto_id' => $productoHistorico->id,
                'area_id' => $area->id,
            ]);
            $stagingIds[] = $staging->id;

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
        }

        $this->assertSame(3, Entrega::where('fuente', 'excel_historico')->count());
        $this->assertSame(0, Inventario::count());
        $this->assertSame(0, MovimientoInventario::count());
        $this->assertSame(0, DB::table('alertas')->count());

        foreach ($stagingIds as $stagingId) {
            $this->assertSame('importado', ExcelImportStaging::query()->find($stagingId)->estado);
        }

        $this->assertStringNotContainsString(
            'InventarioService',
            file_get_contents(app_path('Services/ExcelImportService.php')),
        );
    }
}
