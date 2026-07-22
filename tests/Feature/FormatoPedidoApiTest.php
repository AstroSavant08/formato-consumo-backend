<?php

namespace Tests\Feature;

use App\Models\ConsumoPlan;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormatoPedidoApiTest extends TestCase
{
    use RefreshDatabase;

    private function buildMesesCantidad(array $overrides = []): array
    {
        $meses = [];

        for ($mes = 0; $mes <= 11; $mes++) {
            $meses[] = array_merge([
                'mes' => $mes,
                'cantidad' => 0,
            ], $overrides[$mes] ?? []);
        }

        return $meses;
    }

    private function buildConsumoMeses(array $overrides = []): array
    {
        $meses = [];

        for ($mes = 0; $mes <= 11; $mes++) {
            $meses[] = array_merge([
                'mes' => $mes,
                'cantidad' => 0,
                'existencia' => 0,
                'dinero_solicitar' => null,
            ], $overrides[$mes] ?? []);
        }

        return $meses;
    }

    private function createProducto(string $nombre = 'Café'): Producto
    {
        return Producto::query()->create([
            'nombre' => $nombre,
            'nombre_normalizado' => mb_strtolower($nombre),
            'activo' => true,
        ]);
    }

    private function buildFormatoPedidoPayload(int $anio, Producto $producto, array $overrides = []): array
    {
        return array_merge([
            'anio' => $anio,
            'firma' => [
                'fecha' => '21/7/2026',
                'solicitado_por' => 'Nombre A',
                'autorizado_por' => 'Nombre B',
                'cantidad_dinero' => '2300000 COP',
            ],
            'pedido_especial' => [
                [
                    'orden' => 1,
                    'que' => 'Item especial',
                    'cantidad' => 5,
                ],
            ],
            'productos' => [
                [
                    'producto_id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'stock_debido' => 25,
                    'orden' => 1,
                    'dinero_solicitado' => 150000,
                    'meses' => $this->buildMesesCantidad([
                        0 => ['cantidad' => 10],
                    ]),
                ],
            ],
        ], $overrides);
    }

    public function test_show_returns_404_when_plan_does_not_exist(): void
    {
        $response = $this->getJson('/api/v1/formato-pedido/2026');

        $response->assertNotFound()
            ->assertJson([
                'message' => 'No existe formato de pedido para el año 2026.',
                'data' => null,
            ]);
    }

    public function test_can_create_and_retrieve_formato_pedido_plan(): void
    {
        $producto = $this->createProducto();
        $payload = $this->buildFormatoPedidoPayload(2026, $producto);

        $saveResponse = $this->putJson('/api/v1/formato-pedido/2026', $payload);

        $saveResponse->assertOk()
            ->assertJsonPath('data.anio', 2026)
            ->assertJsonPath('data.tipo', 'formato_pedido')
            ->assertJsonPath('data.firma.solicitado_por', 'Nombre A')
            ->assertJsonPath('data.firma.cantidad_dinero', '2300000 COP')
            ->assertJsonPath('data.pedido_especial.0.que', 'Item especial')
            ->assertJsonPath('data.pedido_especial.0.cantidad', '5.00')
            ->assertJsonCount(1, 'data.productos')
            ->assertJsonPath('data.productos.0.stock_debido', '25.00')
            ->assertJsonPath('data.productos.0.dinero_solicitado', '150000.00')
            ->assertJsonPath('data.productos.0.meses.0.cantidad', '10.00')
            ->assertJsonPath('data.productos.0.meses.0.existencia', '0.00')
            ->assertJsonPath('data.productos.0.meses.0.dinero_solicitar', null)
            ->assertJsonPath('message', 'Formato de pedido guardado correctamente.');

        $this->assertDatabaseHas('consumo_planes', [
            'anio' => 2026,
            'tipo' => 'formato_pedido',
            'solicitado_por' => 'Nombre A',
            'cantidad_dinero_solicitada' => '2300000 COP',
        ]);

        $getResponse = $this->getJson('/api/v1/formato-pedido/2026');

        $getResponse->assertOk()
            ->assertJsonPath('data.anio', 2026)
            ->assertJsonCount(12, 'data.productos.0.meses')
            ->assertJsonPath('data.pedido_especial.0.que', 'Item especial');
    }

    public function test_update_replaces_existing_plan_without_duplicates(): void
    {
        $producto = $this->createProducto();

        $this->putJson('/api/v1/formato-pedido/2026', $this->buildFormatoPedidoPayload(2026, $producto))
            ->assertOk();

        $updatedPayload = $this->buildFormatoPedidoPayload(2026, $producto, [
            'firma' => [
                'fecha' => '1/1/2027',
                'solicitado_por' => 'Actualizado',
                'autorizado_por' => 'Autorizador',
                'cantidad_dinero' => '500000 COP',
            ],
            'pedido_especial' => [
                [
                    'orden' => 1,
                    'que' => 'Novedad actualizada',
                    'cantidad' => 2,
                ],
            ],
            'productos' => [
                [
                    'producto_id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'stock_debido' => 30,
                    'orden' => 1,
                    'dinero_solicitado' => 200000,
                    'meses' => $this->buildMesesCantidad([
                        2 => ['cantidad' => 7],
                    ]),
                ],
            ],
        ]);

        $this->putJson('/api/v1/formato-pedido/2026', $updatedPayload)
            ->assertOk()
            ->assertJsonPath('data.productos.0.stock_debido', '30.00')
            ->assertJsonPath('data.productos.0.dinero_solicitado', '200000.00')
            ->assertJsonPath('data.productos.0.meses.2.cantidad', '7.00')
            ->assertJsonPath('data.firma.solicitado_por', 'Actualizado')
            ->assertJsonPath('data.pedido_especial.0.que', 'Novedad actualizada');

        $this->assertDatabaseCount('consumo_planes', 1);
        $this->assertDatabaseCount('consumo_plan_lineas', 1);
        $this->assertDatabaseCount('consumo_plan_meses', 12);
        $this->assertDatabaseCount('consumo_plan_pedidos_especiales', 1);
    }

    public function test_formato_pedido_and_consumo_anio_can_coexist_for_same_year(): void
    {
        $producto = $this->createProducto();

        $this->putJson('/api/v1/formato-pedido/2026', $this->buildFormatoPedidoPayload(2026, $producto))
            ->assertOk();

        $consumoPayload = [
            'anio' => 2026,
            'productos' => [
                [
                    'producto_id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'stock_debido' => 15,
                    'orden' => 1,
                    'meses' => $this->buildConsumoMeses([
                        0 => ['cantidad' => 99, 'existencia' => 1],
                    ]),
                ],
            ],
        ];

        $this->putJson('/api/v1/consumo-anio/2026', $consumoPayload)
            ->assertOk()
            ->assertJsonPath('data.tipo', 'consumo_anio')
            ->assertJsonPath('data.productos.0.meses.0.cantidad', '99.00');

        $this->assertDatabaseCount('consumo_planes', 2);

        $this->getJson('/api/v1/formato-pedido/2026')
            ->assertOk()
            ->assertJsonPath('data.tipo', 'formato_pedido')
            ->assertJsonPath('data.productos.0.meses.0.cantidad', '10.00');

        $this->getJson('/api/v1/consumo-anio/2026')
            ->assertOk()
            ->assertJsonPath('data.tipo', 'consumo_anio')
            ->assertJsonPath('data.productos.0.meses.0.cantidad', '99.00');
    }

    public function test_formato_pedido_does_not_modify_consumo_anio_plan(): void
    {
        $producto = $this->createProducto();

        $consumoPayload = [
            'anio' => 2026,
            'productos' => [
                [
                    'producto_id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'stock_debido' => 15,
                    'orden' => 1,
                    'meses' => $this->buildConsumoMeses([
                        0 => ['cantidad' => 42, 'existencia' => 3, 'dinero_solicitar' => 1000],
                    ]),
                ],
            ],
        ];

        $this->putJson('/api/v1/consumo-anio/2026', $consumoPayload)->assertOk();

        $this->putJson('/api/v1/formato-pedido/2026', $this->buildFormatoPedidoPayload(2026, $producto))
            ->assertOk();

        $this->getJson('/api/v1/consumo-anio/2026')
            ->assertOk()
            ->assertJsonPath('data.productos.0.meses.0.cantidad', '42.00')
            ->assertJsonPath('data.productos.0.meses.0.existencia', '3.00')
            ->assertJsonPath('data.productos.0.meses.0.dinero_solicitar', '1000.00');
    }

    public function test_invalid_producto_id_is_rejected(): void
    {
        $response = $this->putJson('/api/v1/formato-pedido/2026', [
            'anio' => 2026,
            'firma' => [
                'fecha' => '',
                'solicitado_por' => '',
                'autorizado_por' => '',
                'cantidad_dinero' => '',
            ],
            'pedido_especial' => [],
            'productos' => [
                [
                    'producto_id' => 999999,
                    'nombre' => 'Invalido',
                    'stock_debido' => 10,
                    'orden' => 1,
                    'dinero_solicitado' => null,
                    'meses' => $this->buildMesesCantidad(),
                ],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('consumo_planes', 0);
    }

    public function test_duplicate_meses_are_rejected(): void
    {
        $producto = $this->createProducto();

        $response = $this->putJson('/api/v1/formato-pedido/2026', [
            'anio' => 2026,
            'firma' => [
                'fecha' => '',
                'solicitado_por' => '',
                'autorizado_por' => '',
                'cantidad_dinero' => '',
            ],
            'pedido_especial' => [],
            'productos' => [
                [
                    'producto_id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'stock_debido' => 10,
                    'orden' => 1,
                    'dinero_solicitado' => null,
                    'meses' => [
                        ['mes' => 0, 'cantidad' => 1],
                        ['mes' => 0, 'cantidad' => 2],
                    ],
                ],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('consumo_planes', 0);
    }

    public function test_mismatched_anio_in_body_is_rejected(): void
    {
        $producto = $this->createProducto();

        $response = $this->putJson('/api/v1/formato-pedido/2026', $this->buildFormatoPedidoPayload(2025, $producto));

        $response->assertUnprocessable();
        $this->assertDatabaseCount('consumo_planes', 0);
    }
}
