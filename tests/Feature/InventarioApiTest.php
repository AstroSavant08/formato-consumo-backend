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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventarioApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticateApiUser();
    }

    private function createProducto(string $nombre = 'Producto inventario api'): Producto
    {
        return Producto::query()->create([
            'nombre' => $nombre,
            'nombre_normalizado' => TextNormalizer::normalize($nombre),
            'unidad_default' => 'UND',
            'stock_minimo_referencia' => 15,
            'activo' => true,
            'es_historico_excel' => false,
        ]);
    }

    private function createInventarioViaApi(Producto $producto, float $stockInicial = 100, ?float $stockMinimo = 20): array
    {
        $payload = ['stock_inicial' => $stockInicial];
        if ($stockMinimo !== null) {
            $payload['stock_minimo'] = $stockMinimo;
        }

        $response = $this->postJson("/api/v1/inventarios/{$producto->id}/inicial", $payload);
        $response->assertCreated();

        return $response->json('data');
    }

    public function test_get_inventarios_returns_empty_list(): void
    {
        $this->getJson('/api/v1/inventarios')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_get_inventarios_returns_inventarios_with_producto(): void
    {
        $producto = $this->createProducto('Jabon liquido');
        $this->createInventarioViaApi($producto, 100, 20);

        $this->getJson('/api/v1/inventarios')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.producto_id', $producto->id)
            ->assertJsonPath('data.0.producto.nombre', 'Jabon liquido')
            ->assertJsonPath('data.0.stock_fisico', 100)
            ->assertJsonPath('data.0.stock_disponible', 100);
    }

    public function test_get_inventarios_avoids_unnecessary_n_plus_one(): void
    {
        $productoA = $this->createProducto('Producto A');
        $productoB = $this->createProducto('Producto B');
        $this->createInventarioViaApi($productoA, 10);
        $this->createInventarioViaApi($productoB, 20);

        DB::enableQueryLog();

        $this->getJson('/api/v1/inventarios')->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queryCount);
    }

    public function test_get_inventario_by_producto_returns_existing_inventory(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 75, 10);

        $this->getJson("/api/v1/inventarios/{$producto->id}")
            ->assertOk()
            ->assertJsonPath('data.producto_id', $producto->id)
            ->assertJsonPath('data.stock_fisico', 75)
            ->assertJsonPath('data.stock_disponible', 75);
    }

    public function test_get_inventario_by_producto_returns_404_when_inventory_missing(): void
    {
        $producto = $this->createProducto();

        $this->getJson("/api/v1/inventarios/{$producto->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'El producto no tiene inventario configurado.');
    }

    public function test_get_inventario_by_producto_returns_404_when_product_missing(): void
    {
        $this->getJson('/api/v1/inventarios/99999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Producto no encontrado.');
    }

    public function test_post_inventario_inicial_creates_inventory(): void
    {
        $producto = $this->createProducto();

        $this->postJson("/api/v1/inventarios/{$producto->id}/inicial", [
            'stock_inicial' => 100,
            'stock_minimo' => 20,
        ])
            ->assertCreated()
            ->assertJsonPath('data.stock_fisico', 100)
            ->assertJsonPath('data.stock_minimo', 20)
            ->assertJsonPath('data.stock_disponible', 100);

        $this->assertDatabaseHas('inventarios', [
            'producto_id' => $producto->id,
            'stock_fisico' => 100,
            'stock_minimo' => 20,
        ]);
    }

    public function test_post_inventario_inicial_rejects_negative_stock_inicial(): void
    {
        $producto = $this->createProducto();

        $this->postJson("/api/v1/inventarios/{$producto->id}/inicial", [
            'stock_inicial' => -1,
        ])->assertStatus(422);
    }

    public function test_post_inventario_inicial_rejects_negative_stock_minimo(): void
    {
        $producto = $this->createProducto();

        $this->postJson("/api/v1/inventarios/{$producto->id}/inicial", [
            'stock_inicial' => 10,
            'stock_minimo' => -5,
        ])->assertStatus(422);
    }

    public function test_post_inventario_inicial_rejects_duplicate_inventory(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 10);

        $this->postJson("/api/v1/inventarios/{$producto->id}/inicial", [
            'stock_inicial' => 20,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ya existe inventario para este producto.');
    }

    public function test_post_inventario_inicial_does_not_create_movements(): void
    {
        $producto = $this->createProducto();

        $this->postJson("/api/v1/inventarios/{$producto->id}/inicial", [
            'stock_inicial' => 100,
        ])->assertCreated();

        $this->assertSame(0, MovimientoInventario::count());
    }

    public function test_post_entrada_increments_stock_and_creates_movement(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 100);

        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [
            'cantidad' => 50,
            'referencia_tipo' => 'compra',
            'referencia_id' => 1,
            'observaciones' => 'Entrada inicial de compra',
        ])
            ->assertCreated()
            ->assertJsonPath('data.stock_anterior', 100)
            ->assertJsonPath('data.stock_posterior', 150)
            ->assertJsonPath('data.movimiento.tipo', 'entrada')
            ->assertJsonPath('data.movimiento.cantidad', 50)
            ->assertJsonPath('data.inventario.stock_fisico', 150);

        $this->assertDatabaseHas('inventarios', [
            'producto_id' => $producto->id,
            'stock_fisico' => 150,
        ]);
    }

    public function test_post_entrada_rejects_zero_quantity(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 100);

        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [
            'cantidad' => 0,
        ])->assertStatus(422);
    }

    public function test_post_entrada_rejects_negative_quantity(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 100);

        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [
            'cantidad' => -10,
        ])->assertStatus(422);
    }

    public function test_post_entrada_rejects_product_without_inventory(): void
    {
        $producto = $this->createProducto();

        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [
            'cantidad' => 10,
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'No existe inventario para este producto.');
    }

    public function test_post_ajuste_positive(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 100);

        $this->postJson("/api/v1/inventarios/{$producto->id}/ajuste", [
            'nuevo_stock' => 150,
            'observaciones' => 'Conteo físico de bodega',
        ])
            ->assertCreated()
            ->assertJsonPath('data.stock_anterior', 100)
            ->assertJsonPath('data.stock_posterior', 150)
            ->assertJsonPath('data.movimiento.tipo', 'ajuste')
            ->assertJsonPath('data.movimiento.cantidad', 50);
    }

    public function test_post_ajuste_negative(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 100);

        $this->postJson("/api/v1/inventarios/{$producto->id}/ajuste", [
            'nuevo_stock' => 80,
            'observaciones' => 'Corrección por conteo',
        ])
            ->assertCreated()
            ->assertJsonPath('data.stock_anterior', 100)
            ->assertJsonPath('data.stock_posterior', 80)
            ->assertJsonPath('data.movimiento.tipo', 'ajuste')
            ->assertJsonPath('data.movimiento.cantidad', 20);
    }

    public function test_post_ajuste_rejects_negative_stock(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 100);

        $this->postJson("/api/v1/inventarios/{$producto->id}/ajuste", [
            'nuevo_stock' => -1,
            'observaciones' => 'Intento inválido',
        ])->assertStatus(422);

        $this->assertSame('100.00', Inventario::query()->where('producto_id', $producto->id)->value('stock_fisico'));
        $this->assertSame(0, MovimientoInventario::count());
    }

    public function test_post_ajuste_rejects_missing_observaciones(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 100);

        $this->postJson("/api/v1/inventarios/{$producto->id}/ajuste", [
            'nuevo_stock' => 90,
            'observaciones' => '   ',
        ])->assertStatus(422);
    }

    public function test_get_movimientos_returns_movements(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 100);

        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [
            'cantidad' => 25,
        ])->assertCreated();

        $this->getJson('/api/v1/movimientos-inventario')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tipo', 'entrada')
            ->assertJsonPath('data.0.producto_id', $producto->id)
            ->assertJsonPath('data.0.stock_anterior', 100)
            ->assertJsonPath('data.0.stock_posterior', 125);
    }

    public function test_get_movimientos_filters_by_producto_id(): void
    {
        $productoA = $this->createProducto('Producto A');
        $productoB = $this->createProducto('Producto B');
        $this->createInventarioViaApi($productoA, 100);
        $this->createInventarioViaApi($productoB, 50);

        $this->postJson("/api/v1/inventarios/{$productoA->id}/entrada", ['cantidad' => 10])->assertCreated();
        $this->postJson("/api/v1/inventarios/{$productoB->id}/entrada", ['cantidad' => 5])->assertCreated();

        $this->getJson("/api/v1/movimientos-inventario?producto_id={$productoA->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.producto_id', $productoA->id);
    }

    public function test_get_movimientos_filters_by_tipo(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 100);
        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", ['cantidad' => 10])->assertCreated();
        $this->postJson("/api/v1/inventarios/{$producto->id}/ajuste", [
            'nuevo_stock' => 120,
            'observaciones' => 'Ajuste de prueba',
        ])->assertCreated();

        $this->getJson('/api/v1/movimientos-inventario?tipo=ajuste')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tipo', 'ajuste');
    }

    public function test_get_movimientos_filters_by_date_range(): void
    {
        $producto = $this->createProducto();
        $this->createInventarioViaApi($producto, 100);
        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", ['cantidad' => 10])->assertCreated();

        $movimiento = MovimientoInventario::query()->first();
        $movimiento->forceFill(['created_at' => '2024-01-15 10:00:00'])->save();

        $this->getJson('/api/v1/movimientos-inventario?fecha_desde=2024-01-01&fecha_hasta=2024-01-31')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/movimientos-inventario?fecha_desde=2025-01-01')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_get_movimientos_rejects_invalid_tipo_filter(): void
    {
        $this->getJson('/api/v1/movimientos-inventario?tipo=invalido')
            ->assertStatus(422);
    }

    public function test_inventory_endpoints_do_not_modify_block4_records(): void
    {
        $area = Area::query()->create([
            'codigo' => TextNormalizer::normalize('MANTENIMIENTO'),
            'nombre' => 'MANTENIMIENTO',
            'activo' => true,
        ]);
        $productoHistorico = $this->createProducto('Producto histórico bloque 4');

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
                'excel_hash' => hash('sha256', "bloque4-{$filaExcel}"),
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
                'notas' => 'Homologación de prueba',
            ]);
        }

        $productoOperativo = $this->createProducto('Producto operativo inventario');
        $this->createInventarioViaApi($productoOperativo, 100);
        $this->postJson("/api/v1/inventarios/{$productoOperativo->id}/entrada", [
            'cantidad' => 10,
        ])->assertCreated();

        $this->assertSame(3, Entrega::where('fuente', 'excel_historico')->count());
        $this->assertSame(3, ExcelImportHomologacion::count());
        $this->assertSame(3, ExcelImportStaging::where('estado', 'importado')->count());
        $this->assertSame(1, Inventario::count());
        $this->assertSame(1, MovimientoInventario::count());
        $this->assertSame(0, DB::table('alertas')->count());
    }
}
