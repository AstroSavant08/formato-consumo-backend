<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Entrega;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato API consumido por el formulario de entregas (Bloque 5.8).
 */
class EntregaStockFormApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticateApiUser();
    }

    private function createProducto(
        string $nombre = 'Producto formulario entregas',
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
        float $stockFisico = 30,
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

    private function entregaPayload(Producto $producto, Area $area, float $cantidad = 10, string $unidad = 'UND'): array
    {
        return $this->withEntregaPersona([
            'fecha' => '2026-07-24',
            'producto_id' => $producto->id,
            'area_id' => $area->id,
            'cantidad' => $cantidad,
            'unidad' => $unidad,
            'quien_recibe' => 'Receptor formulario',
            'entregado_por' => 'Entregador formulario',
        ]);
    }

    public function test_consulta_inventario_operativo_expone_campos_para_formulario_entregas(): void
    {
        $producto = $this->createProducto();
        $this->createInventario($producto, 30, 0, 0, 20);

        $this->getJson("/api/v1/inventarios/{$producto->id}")
            ->assertOk()
            ->assertJsonPath('data.stock_fisico', 30)
            ->assertJsonPath('data.stock_reserva', 0)
            ->assertJsonPath('data.stock_comprometido', 0)
            ->assertJsonPath('data.stock_disponible', 30)
            ->assertJsonPath('data.stock_minimo', 20);
    }

    public function test_consulta_inventario_sin_configuracion_retorna_404_claro(): void
    {
        $producto = $this->createProducto();

        $this->getJson("/api/v1/inventarios/{$producto->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'El producto no tiene inventario configurado.');
    }

    public function test_entrega_exitosa_permite_consultar_stock_actualizado(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea();
        $this->createInventario($producto, 30);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 10))
            ->assertCreated()
            ->assertJsonPath('data.fuente', 'sistema');

        $this->getJson("/api/v1/inventarios/{$producto->id}")
            ->assertOk()
            ->assertJsonPath('data.stock_fisico', 20)
            ->assertJsonPath('data.stock_disponible', 20);

        $this->assertSame(1, Entrega::count());
        $this->assertSame(1, MovimientoInventario::count());
    }

    public function test_entrega_rechaza_stock_insuficiente_con_detalle_para_ui(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea();
        $this->createInventario($producto, 10);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 15))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stock insuficiente para realizar la entrega.')
            ->assertJsonPath('data.stock_disponible', 10)
            ->assertJsonPath('data.cantidad_solicitada', 15);

        $this->assertSame(0, Entrega::count());
        $this->assertSame(0, MovimientoInventario::count());
    }

    public function test_entrega_rechaza_unidad_incompatible_con_detalle_para_ui(): void
    {
        $producto = $this->createProducto(unidadDefault: 'UND');
        $area = $this->createArea();
        $this->createInventario($producto, 30);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 5, 'ML'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'La unidad de la operación no coincide con la unidad del producto.')
            ->assertJsonPath('data.unidad_producto', 'UND')
            ->assertJsonPath('data.unidad_recibida', 'ML');

        $this->assertSame(0, Entrega::count());
        $this->assertSame(0, MovimientoInventario::count());
        $this->assertSame('30.00', Inventario::query()->firstOrFail()->stock_fisico);
    }
}
