<?php

namespace Tests\Feature;

use App\Models\ConsumoPlan;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsumoAnioApiTest extends TestCase
{
    use RefreshDatabase;

    private function buildMeses(array $overrides = []): array
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

    private function createProducto(string $nombre = 'Accion caja'): Producto
    {
        return Producto::query()->create([
            'nombre' => $nombre,
            'nombre_normalizado' => mb_strtolower($nombre),
            'activo' => true,
        ]);
    }

    public function test_show_returns_404_when_plan_does_not_exist(): void
    {
        $response = $this->getJson('/api/v1/consumo-anio/2026');

        $response->assertNotFound()
            ->assertJson([
                'message' => 'No existe plan de consumo anual para el año 2026.',
                'data' => null,
            ]);
    }

    public function test_can_create_and_retrieve_consumo_anio_plan(): void
    {
        $producto = $this->createProducto();

        $payload = [
            'anio' => 2026,
            'productos' => [
                [
                    'producto_id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'stock_debido' => 15,
                    'orden' => 1,
                    'meses' => $this->buildMeses([
                        0 => ['cantidad' => 10, 'existencia' => 5, 'dinero_solicitar' => 100000],
                        3 => ['cantidad' => 4, 'existencia' => 2],
                    ]),
                ],
                [
                    'producto_id' => null,
                    'nombre' => 'Producto temporal',
                    'stock_debido' => 0,
                    'orden' => 2,
                    'meses' => $this->buildMeses([
                        1 => ['cantidad' => 3, 'existencia' => 1],
                    ]),
                ],
            ],
        ];

        $saveResponse = $this->putJson('/api/v1/consumo-anio/2026', $payload);

        $saveResponse->assertOk()
            ->assertJsonPath('data.anio', 2026)
            ->assertJsonPath('data.tipo', 'consumo_anio')
            ->assertJsonCount(2, 'data.productos')
            ->assertJsonPath('data.productos.0.meses.0.cantidad', '10.00')
            ->assertJsonPath('data.productos.0.meses.0.existencia', '5.00')
            ->assertJsonPath('data.productos.0.meses.0.dinero_solicitar', '100000.00');

        $this->assertDatabaseCount('consumo_planes', 1);
        $this->assertDatabaseCount('consumo_plan_lineas', 2);
        $this->assertDatabaseCount('consumo_plan_meses', 24);

        $getResponse = $this->getJson('/api/v1/consumo-anio/2026');

        $getResponse->assertOk()
            ->assertJsonPath('data.anio', 2026)
            ->assertJsonCount(2, 'data.productos')
            ->assertJsonCount(12, 'data.productos.0.meses')
            ->assertJsonPath('data.productos.1.nombre', 'Producto temporal')
            ->assertJsonPath('data.productos.1.producto_id', null);
    }

    public function test_update_replaces_existing_plan_without_duplicates(): void
    {
        $producto = $this->createProducto();

        $initialPayload = [
            'anio' => 2026,
            'productos' => [
                [
                    'producto_id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'stock_debido' => 15,
                    'orden' => 1,
                    'meses' => $this->buildMeses([
                        0 => ['cantidad' => 1],
                    ]),
                ],
            ],
        ];

        $this->putJson('/api/v1/consumo-anio/2026', $initialPayload)->assertOk();

        $updatedPayload = [
            'anio' => 2026,
            'productos' => [
                [
                    'producto_id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'stock_debido' => 20,
                    'orden' => 1,
                    'meses' => $this->buildMeses([
                        2 => ['cantidad' => 7, 'existencia' => 3, 'dinero_solicitar' => 50000],
                    ]),
                ],
            ],
        ];

        $this->putJson('/api/v1/consumo-anio/2026', $updatedPayload)
            ->assertOk()
            ->assertJsonPath('data.productos.0.stock_debido', '20.00')
            ->assertJsonPath('data.productos.0.meses.2.cantidad', '7.00');

        $this->assertDatabaseCount('consumo_planes', 1);
        $this->assertDatabaseCount('consumo_plan_lineas', 1);
        $this->assertDatabaseCount('consumo_plan_meses', 12);
    }

    public function test_invalid_payload_is_rejected_and_does_not_persist_partial_data(): void
    {
        $producto = $this->createProducto();

        $response = $this->putJson('/api/v1/consumo-anio/2026', [
            'anio' => 2026,
            'productos' => [
                [
                    'producto_id' => 999999,
                    'nombre' => 'Invalido',
                    'stock_debido' => 10,
                    'orden' => 1,
                    'meses' => $this->buildMeses(),
                ],
            ],
        ]);

        $response->assertUnprocessable();

        $this->assertDatabaseCount('consumo_planes', 0);
        $this->assertDatabaseCount('consumo_plan_lineas', 0);
        $this->assertDatabaseCount('consumo_plan_meses', 0);

        $validResponse = $this->putJson('/api/v1/consumo-anio/2026', [
            'anio' => 2026,
            'productos' => [
                [
                    'producto_id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'stock_debido' => 10,
                    'orden' => 1,
                    'meses' => $this->buildMeses([
                        0 => ['cantidad' => 2],
                    ]),
                ],
            ],
        ]);

        $validResponse->assertOk();
        $this->assertSame(1, ConsumoPlan::query()->count());
    }
}
