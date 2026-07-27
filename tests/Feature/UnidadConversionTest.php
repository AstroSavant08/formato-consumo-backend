<?php



namespace Tests\Feature;



use App\Models\Alerta;

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



class UnidadConversionTest extends TestCase

{

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticateApiUser();
    }



    private function createProducto(string $nombre, ?string $unidadDefault): Producto

    {

        return Producto::query()->create([

            'nombre' => $nombre,

            'nombre_normalizado' => TextNormalizer::normalize($nombre),

            'unidad_default' => $unidadDefault,

            'stock_minimo_referencia' => 10,

            'activo' => true,

            'es_historico_excel' => false,

        ]);

    }



    private function createArea(): Area

    {

        return Area::query()->create([

            'codigo' => TextNormalizer::normalize('MANTENIMIENTO'),

            'nombre' => 'MANTENIMIENTO',

            'activo' => true,

        ]);

    }



    private function createInventario(Producto $producto, float $stockFisico, float $stockMinimo = 10): Inventario

    {

        return Inventario::query()->create([

            'producto_id' => $producto->id,

            'stock_fisico' => $stockFisico,

            'stock_reserva' => 0,

            'stock_comprometido' => 0,

            'stock_minimo' => $stockMinimo,

        ]);

    }



    private function entregaPayload(Producto $producto, Area $area, string $unidad, float $cantidad): array

    {

        return [

            'fecha' => '2026-07-24',

            'producto_id' => $producto->id,

            'area_id' => $area->id,

            'cantidad' => $cantidad,

            'unidad' => $unidad,

            'quien_recibe' => 'Receptor conversion test',

            'entregado_por' => 'Entregador conversion test',

        ];

    }



    public function test_entrada_convierte_ml_a_l(): void

    {

        $producto = $this->createProducto('Producto base litros', 'L');

        $this->createInventario($producto, 10);



        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [

            'cantidad' => 500,

            'unidad' => 'ML',

        ])

            ->assertCreated()

            ->assertJsonPath('data.stock_posterior', 10.5);



        $movimiento = MovimientoInventario::query()->firstOrFail();

        $this->assertSame('0.50', $movimiento->cantidad);

        $this->assertSame('10.50', Inventario::query()->firstOrFail()->stock_fisico);

    }



    public function test_entrada_convierte_l_a_ml(): void

    {

        $producto = $this->createProducto('Producto base mililitros', 'ML');

        $this->createInventario($producto, 1000);



        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [

            'cantidad' => 2,

            'unidad' => 'L',

        ])

            ->assertCreated()

            ->assertJsonPath('data.stock_posterior', 3000);



        $movimiento = MovimientoInventario::query()->firstOrFail();

        $this->assertSame('2000.00', $movimiento->cantidad);

        $this->assertSame('3000.00', Inventario::query()->firstOrFail()->stock_fisico);

    }



    public function test_entrada_convierte_g_a_kg(): void

    {

        $producto = $this->createProducto('Producto base kilogramos', 'KG');

        $this->createInventario($producto, 5);



        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [

            'cantidad' => 500,

            'unidad' => 'G',

        ])

            ->assertCreated()

            ->assertJsonPath('data.stock_posterior', 5.5);



        $this->assertSame('0.50', MovimientoInventario::query()->firstOrFail()->cantidad);

    }



    public function test_entrada_convierte_kg_a_g(): void

    {

        $producto = $this->createProducto('Producto base gramos', 'G');

        $this->createInventario($producto, 1000);



        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [

            'cantidad' => 2,

            'unidad' => 'KG',

        ])

            ->assertCreated()

            ->assertJsonPath('data.stock_posterior', 3000);



        $this->assertSame('2000.00', MovimientoInventario::query()->firstOrFail()->cantidad);

    }



    public function test_entrega_convierte_ml_a_l_y_conserva_unidad_original(): void

    {

        $producto = $this->createProducto('Entrega litros base', 'L');

        $area = $this->createArea();

        $this->createInventario($producto, 10);



        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 'ML', 250))

            ->assertCreated()

            ->assertJsonPath('data.cantidad', '250.00')

            ->assertJsonPath('data.unidad', 'ML');



        $entrega = Entrega::query()->firstOrFail();

        $movimiento = MovimientoInventario::query()->firstOrFail();



        $this->assertSame('250.00', $entrega->cantidad);

        $this->assertSame('ML', $entrega->unidad);

        $this->assertSame('0.25', $movimiento->cantidad);

        $this->assertSame('10.00', $movimiento->stock_anterior);

        $this->assertSame('9.75', $movimiento->stock_posterior);

        $this->assertSame('9.75', Inventario::query()->firstOrFail()->stock_fisico);

    }



    public function test_entrega_convierte_l_a_ml(): void

    {

        $producto = $this->createProducto('Entrega ml base', 'ML');

        $area = $this->createArea();

        $this->createInventario($producto, 5000);



        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 'L', 1))

            ->assertCreated();



        $this->assertSame('1000.00', MovimientoInventario::query()->firstOrFail()->cantidad);

        $this->assertSame('4000.00', Inventario::query()->firstOrFail()->stock_fisico);

    }



    public function test_unidad_incompatible_rechaza_sin_modificar_inventario(): void

    {

        $producto = $this->createProducto('Producto und base', 'UND');

        $area = $this->createArea();

        $this->createInventario($producto, 100);



        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [

            'cantidad' => 10,

            'unidad' => 'ML',

        ])

            ->assertStatus(422)

            ->assertJsonPath('data.unidad_producto', 'UND')

            ->assertJsonPath('data.unidad_recibida', 'ML');



        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 'ML', 5))

            ->assertStatus(422);



        $this->assertSame('100.00', Inventario::query()->firstOrFail()->stock_fisico);

        $this->assertSame(0, MovimientoInventario::count());

        $this->assertSame(0, Entrega::count());

        $this->assertSame(0, Alerta::count());

    }



    public function test_stock_insuficiente_despues_de_convertir(): void

    {

        $producto = $this->createProducto('Stock litros insuficiente', 'L');

        $area = $this->createArea();

        $this->createInventario($producto, 1);



        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 'ML', 1500))

            ->assertStatus(422)

            ->assertJsonPath('message', 'Stock insuficiente para realizar la entrega.')

            ->assertJsonPath('data.stock_disponible', 1)

            ->assertJsonPath('data.cantidad_solicitada', 1500)

            ->assertJsonPath('data.cantidad_convertida', 1.5);



        $this->assertSame(0, Entrega::count());

        $this->assertSame(0, MovimientoInventario::count());

        $this->assertSame('1.00', Inventario::query()->firstOrFail()->stock_fisico);

    }



    public function test_precision_decimal_en_conversion_ml_a_l(): void

    {

        $producto = $this->createProducto('Precision litros', 'L');

        $this->createInventario($producto, 0);



        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [

            'cantidad' => 333,

            'unidad' => 'ML',

        ])->assertCreated();



        $this->assertSame('0.33', MovimientoInventario::query()->firstOrFail()->cantidad);

        $this->assertSame('0.33', Inventario::query()->firstOrFail()->stock_fisico);

    }



    public function test_rechaza_cantidad_cero_o_negativa_en_entrada(): void

    {

        $producto = $this->createProducto('Entrada cero', 'L');

        $this->createInventario($producto, 10);



        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [

            'cantidad' => 0,

            'unidad' => 'ML',

        ])->assertStatus(422);



        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [

            'cantidad' => -5,

            'unidad' => 'ML',

        ])->assertStatus(422);



        $this->assertSame('10.00', Inventario::query()->firstOrFail()->stock_fisico);

        $this->assertSame(0, MovimientoInventario::count());

    }



    public function test_rechaza_cantidad_cero_o_negativa_en_entrega(): void

    {

        $producto = $this->createProducto('Entrega cero', 'L');

        $area = $this->createArea();

        $this->createInventario($producto, 10);



        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 'ML', 0))

            ->assertStatus(422);



        $this->assertSame(0, Entrega::count());

    }



    public function test_atomicidad_revierte_entrega_si_falla_movimiento(): void

    {

        $producto = $this->createProducto('Atomicidad litros', 'L');

        $area = $this->createArea();

        $this->createInventario($producto, 10);



        MovimientoInventario::creating(function () {

            throw new \RuntimeException('Fallo simulado durante movimiento con conversión.');

        });



        try {

            $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 'ML', 500))

                ->assertStatus(500);

        } finally {

            MovimientoInventario::flushEventListeners();

        }



        $this->assertSame(0, Entrega::count());

        $this->assertSame(0, MovimientoInventario::count());

        $this->assertSame('10.00', Inventario::query()->firstOrFail()->stock_fisico);

        $this->assertSame(0, Alerta::count());

    }



    public function test_unidad_default_null_mantiene_comportamiento_sin_conversion(): void

    {

        $producto = $this->createProducto('Producto sin base', null);

        $area = $this->createArea();

        $this->createInventario($producto, 60);



        $this->postJson("/api/v1/inventarios/{$producto->id}/entrada", [

            'cantidad' => 5,

            'unidad' => 'ML',

        ])->assertCreated();



        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 'KG', 10))

            ->assertCreated();



        $this->assertSame('55.00', Inventario::query()->firstOrFail()->stock_fisico);

        $this->assertSame('5.00', MovimientoInventario::query()->where('tipo', 'entrada')->value('cantidad'));

        $this->assertSame('10.00', MovimientoInventario::query()->where('tipo', 'entrega')->value('cantidad'));

    }



    public function test_regresion_fase_1_coincidencia_exacta_und(): void

    {

        $producto = $this->createProducto('Regresion und', 'UND');

        $area = $this->createArea();

        $this->createInventario($producto, 80);



        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, ' und ', 20))

            ->assertCreated();



        $this->assertSame('60.00', Inventario::query()->firstOrFail()->stock_fisico);

    }



    public function test_integridad_bloque_4_no_revalida_historico(): void

    {

        $area = $this->createArea();

        $productoHistorico = $this->createProducto('Producto histórico conversion', 'ML');



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

                'excel_hash' => hash('sha256', "conversion-historico-{$filaExcel}"),

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



        $this->assertSame(3, Entrega::where('fuente', 'excel_historico')->count());

        $this->assertSame(0, MovimientoInventario::count());

    }



    public function test_regresion_entrega_operativa_und_sin_conversion_descuenta_stock(): void

    {

        $producto = $this->createProducto('Regresion 5.4 und', 'UND');

        $area = $this->createArea();

        $this->createInventario($producto, 80);



        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 'UND', 20))

            ->assertCreated();



        $this->assertSame('60.00', Inventario::query()->firstOrFail()->stock_fisico);

        $this->assertSame('20.00', Entrega::query()->firstOrFail()->cantidad);

        $this->assertSame('20.00', MovimientoInventario::query()->firstOrFail()->cantidad);

    }

}


