<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Area;
use App\Models\ConfiguracionAlerta;
use App\Models\Entrega;
use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Services\AlertaInventarioService;
use App\Services\InventarioService;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertaInventarioTest extends TestCase
{
    use RefreshDatabase;

    private function createProducto(
        string $nombre = 'Producto alerta test',
        ?string $unidadDefault = 'UND',
    ): Producto {
        return Producto::query()->create([
            'nombre' => $nombre,
            'nombre_normalizado' => TextNormalizer::normalize($nombre),
            'unidad_default' => $unidadDefault,
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
        float $stockFisico,
        float $stockMinimo = 20,
        float $stockReserva = 0,
        float $stockComprometido = 0,
    ): Inventario {
        return Inventario::query()->create([
            'producto_id' => $producto->id,
            'stock_fisico' => $stockFisico,
            'stock_reserva' => $stockReserva,
            'stock_comprometido' => $stockComprometido,
            'stock_minimo' => $stockMinimo,
        ]);
    }

    private function entregaPayload(Producto $producto, Area $area, float $cantidad): array
    {
        return [
            'fecha' => '2026-07-24',
            'producto_id' => $producto->id,
            'area_id' => $area->id,
            'cantidad' => $cantidad,
            'unidad' => 'UND',
            'quien_recibe' => 'Receptor alerta test',
            'entregado_por' => 'Entregador alerta test',
        ];
    }

    public function test_no_crea_alerta_cuando_stock_disponible_esta_por_encima_del_minimo(): void
    {
        $producto = $this->createProducto();
        $inventario = $this->createInventario($producto, 60, 20);

        app(AlertaInventarioService::class)->verificarStockMinimo($inventario);

        $this->assertSame(0, Alerta::count());
    }

    public function test_no_crea_alerta_cuando_stock_disponible_es_igual_al_minimo(): void
    {
        $producto = $this->createProducto();
        $inventario = $this->createInventario($producto, 20, 20);

        app(AlertaInventarioService::class)->verificarStockMinimo($inventario);

        $this->assertSame(0, Alerta::count());
    }

    public function test_crea_alerta_amarilla_cuando_stock_disponible_esta_por_debajo_del_minimo(): void
    {
        $producto = $this->createProducto();
        $inventario = $this->createInventario($producto, 15, 20);

        $alerta = app(AlertaInventarioService::class)->verificarStockMinimo($inventario);

        $this->assertNotNull($alerta);
        $this->assertSame('stock_minimo', $alerta->tipo);
        $this->assertSame('amarillo', $alerta->severidad);
        $this->assertSame($producto->id, $alerta->producto_id);
        $this->assertNull($alerta->area_id);
        $this->assertFalse($alerta->leida);
        $this->assertEquals(15.0, $alerta->metadata['stock_disponible']);
        $this->assertEquals(20.0, $alerta->metadata['stock_minimo']);
    }

    public function test_usa_stock_disponible_con_reserva_y_comprometido(): void
    {
        $producto = $this->createProducto();
        $inventario = $this->createInventario($producto, 60, 20, 45, 0);

        app(AlertaInventarioService::class)->verificarStockMinimo($inventario);

        $alerta = Alerta::query()->firstOrFail();
        $this->assertEquals(15.0, $alerta->metadata['stock_disponible']);
        $this->assertEquals(45.0, $alerta->metadata['stock_reserva']);
    }

    public function test_no_duplica_alerta_activa_no_leida(): void
    {
        $producto = $this->createProducto();
        $inventario = $this->createInventario($producto, 15, 20);
        $service = app(AlertaInventarioService::class);

        $service->verificarStockMinimo($inventario);
        $service->verificarStockMinimo($inventario->fresh());

        $this->assertSame(1, Alerta::count());
    }

    public function test_recuperacion_marca_alerta_activa_como_leida(): void
    {
        $producto = $this->createProducto();
        $inventario = $this->createInventario($producto, 15, 20);
        $service = app(AlertaInventarioService::class);

        $service->verificarStockMinimo($inventario);
        $this->assertFalse(Alerta::query()->firstOrFail()->leida);

        $inventario->update(['stock_fisico' => 25]);
        $service->verificarStockMinimo($inventario->fresh());

        $this->assertTrue(Alerta::query()->firstOrFail()->leida);
        $this->assertSame(1, Alerta::count());
    }

    public function test_entrada_operativa_bajo_minimo_crea_alerta(): void
    {
        $producto = $this->createProducto();
        $this->createInventario($producto, 10, 20);

        app(InventarioService::class)->registrarEntrada($producto->id, 5);

        $this->assertSame('15.00', Inventario::query()->firstOrFail()->stock_fisico);
        $this->assertSame(1, Alerta::where('tipo', 'stock_minimo')->count());
    }

    public function test_ajuste_operativo_bajo_minimo_crea_alerta(): void
    {
        $producto = $this->createProducto();
        $this->createInventario($producto, 30, 20);

        app(InventarioService::class)->registrarAjuste($producto->id, 15, 'Conteo físico bajo mínimo');

        $alerta = Alerta::query()->firstOrFail();
        $this->assertSame('stock_minimo', $alerta->tipo);
        $this->assertEquals(15.0, $alerta->metadata['stock_disponible']);
    }

    public function test_entrega_operativa_bajo_minimo_crea_entrega_movimiento_y_alerta(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea();
        $this->createInventario($producto, 25, 20);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 6))
            ->assertCreated();

        $this->assertSame(1, Entrega::count());
        $this->assertSame(1, MovimientoInventario::count());
        $this->assertSame('19.00', Inventario::query()->firstOrFail()->stock_fisico);
        $this->assertSame(1, Alerta::where('tipo', 'stock_minimo')->count());
    }

    public function test_entrada_operativa_recupera_alerta_cuando_stock_supera_minimo(): void
    {
        $producto = $this->createProducto();
        $inventario = $this->createInventario($producto, 15, 20);

        app(AlertaInventarioService::class)->verificarStockMinimo($inventario);
        $this->assertFalse(Alerta::query()->firstOrFail()->leida);

        app(InventarioService::class)->registrarEntrada($producto->id, 10);

        $this->assertTrue(Alerta::query()->firstOrFail()->leida);
        $this->assertSame('25.00', Inventario::query()->firstOrFail()->stock_fisico);
    }

    public function test_entregas_historicas_no_generan_alertas(): void
    {
        $area = $this->createArea();
        $productoHistorico = $this->createProducto('Producto histórico alertas', 'ML');

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
                'excel_hash' => hash('sha256', "alerta-historico-{$filaExcel}"),
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
        }

        $this->assertSame(0, Alerta::count());
        $this->assertSame(3, Entrega::where('fuente', 'excel_historico')->count());
        $this->assertSame(3, ExcelImportHomologacion::count());
    }

    public function test_bloque_4_permanece_intacto_despues_de_operaciones_con_alertas(): void
    {
        $area = $this->createArea();
        $productoHistorico = $this->createProducto('Producto bloque 4 alertas', 'UND');

        $staging = ExcelImportStaging::query()->create([
            'fila_excel' => 61,
            'fecha_raw' => '45330',
            'producto_raw' => 'PRODUCTO HIST',
            'cantidad_raw' => '10',
            'unidad_raw' => 'UND',
            'area_raw' => 'MANTENIMIENTO',
            'quien_recibe_raw' => 'Receptor',
            'entrega_raw' => 'Entregador',
            'estado' => 'importado',
            'excel_hash' => hash('sha256', 'alerta-bloque4'),
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

        $productoOperativo = $this->createProducto('Producto operativo alertas');
        $this->createInventario($productoOperativo, 25, 20);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($productoOperativo, $area, 6))
            ->assertCreated();

        $this->assertSame(1, Entrega::where('fuente', 'excel_historico')->count());
        $this->assertSame(1, ExcelImportHomologacion::count());
        $this->assertSame('UND', ExcelImportStaging::query()->find($staging->id)->unidad_raw);
        $this->assertSame(1, Alerta::where('producto_id', $productoOperativo->id)->count());
    }

    public function test_atomicidad_revierte_operacion_si_falla_creacion_de_alerta(): void
    {
        $producto = $this->createProducto();
        $this->createInventario($producto, 25, 20);

        Alerta::creating(function () {
            throw new \RuntimeException('Fallo crítico simulado durante creación de alerta.');
        });

        try {
            app(InventarioService::class)->registrarAjuste($producto->id, 15, 'Ajuste con fallo de alerta');
            $this->fail('Se esperaba una excepción crítica.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Fallo crítico simulado', $exception->getMessage());
        } finally {
            Alerta::flushEventListeners();
        }

        $this->assertSame('25.00', Inventario::query()->firstOrFail()->stock_fisico);
        $this->assertSame(0, MovimientoInventario::count());
        $this->assertSame(0, Alerta::count());
    }

    public function test_respeta_configuracion_desactivada_sin_crear_alerta(): void
    {
        ConfiguracionAlerta::query()->create([
            'clave' => AlertaInventarioService::CLAVE_CONFIG_STOCK_MINIMO,
            'descripcion' => 'Alerta de stock mínimo',
            'umbral_verde' => 15,
            'umbral_amarillo' => 40,
            'umbral_rojo' => 40,
            'activo' => false,
        ]);

        $producto = $this->createProducto();
        $inventario = $this->createInventario($producto, 15, 20);

        app(AlertaInventarioService::class)->verificarStockMinimo($inventario);

        $this->assertSame(0, Alerta::count());
    }
}
