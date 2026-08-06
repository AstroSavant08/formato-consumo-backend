<?php

namespace Tests\Feature;

use App\Models\PrecioHistorico;
use App\Models\Producto;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrecioHistoricoApiTest extends TestCase
{
    use RefreshDatabase;

    private function createProducto(string $nombre = 'Cafe piloto'): Producto
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

    public function test_get_precio_vigente_returns_null_when_missing(): void
    {
        $producto = $this->createProducto();

        $this->getJson("/api/v1/productos/{$producto->id}/precio-vigente?fecha=2026-07-01")
            ->assertOk()
            ->assertJsonPath('data.precio', null)
            ->assertJsonPath('data.producto_id', $producto->id);
    }

    public function test_get_precio_vigente_returns_current_price(): void
    {
        $producto = $this->createProducto();

        PrecioHistorico::query()->create([
            'producto_id' => $producto->id,
            'precio' => 12500,
            'vigente_desde' => '2026-01-01',
            'vigente_hasta' => null,
        ]);

        $this->getJson("/api/v1/productos/{$producto->id}/precio-vigente?fecha=2026-07-15")
            ->assertOk()
            ->assertJsonPath('data.precio', 12500)
            ->assertJsonPath('data.vigente_desde', '2026-01-01');
    }

    public function test_get_precio_vigente_respects_vigencia_hasta(): void
    {
        $producto = $this->createProducto();

        PrecioHistorico::query()->create([
            'producto_id' => $producto->id,
            'precio' => 9000,
            'vigente_desde' => '2025-01-01',
            'vigente_hasta' => '2025-12-31',
        ]);

        $this->getJson("/api/v1/productos/{$producto->id}/precio-vigente?fecha=2026-01-01")
            ->assertOk()
            ->assertJsonPath('data.precio', null);
    }

    public function test_post_precio_historico_requires_auth(): void
    {
        $this->clearAuthentication();
        $producto = $this->createProducto();

        $this->postJson('/api/v1/precios-historicos', [
            'producto_id' => $producto->id,
            'precio' => 15000,
            'vigente_desde' => '2026-07-01',
        ])->assertUnauthorized();
    }

    public function test_post_precio_historico_creates_record(): void
    {
        $this->authenticateApiUser();
        $producto = $this->createProducto();

        $this->postJson('/api/v1/precios-historicos', [
            'producto_id' => $producto->id,
            'precio' => 15000,
            'vigente_desde' => '2026-07-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.precio', 15000)
            ->assertJsonPath('data.producto_id', $producto->id);

        $this->assertSame(1, PrecioHistorico::count());
    }

    public function test_post_precio_historico_closes_previous_open_vigencia(): void
    {
        $this->authenticateApiUser();
        $producto = $this->createProducto();

        PrecioHistorico::query()->create([
            'producto_id' => $producto->id,
            'precio' => 10000,
            'vigente_desde' => '2026-01-01',
            'vigente_hasta' => null,
        ]);

        $this->postJson('/api/v1/precios-historicos', [
            'producto_id' => $producto->id,
            'precio' => 12000,
            'vigente_desde' => '2026-07-01',
        ])->assertCreated();

        $anterior = PrecioHistorico::query()->orderBy('id')->firstOrFail();
        $this->assertSame('2026-06-30', $anterior->vigente_hasta?->toDateString());

        $this->getJson("/api/v1/productos/{$producto->id}/precio-vigente?fecha=2026-07-15")
            ->assertOk()
            ->assertJsonPath('data.precio', 12000);
    }

    public function test_get_precios_historicos_lists_by_producto(): void
    {
        $producto = $this->createProducto();

        PrecioHistorico::query()->create([
            'producto_id' => $producto->id,
            'precio' => 10000,
            'vigente_desde' => '2026-01-01',
            'vigente_hasta' => '2026-06-30',
        ]);
        PrecioHistorico::query()->create([
            'producto_id' => $producto->id,
            'precio' => 12000,
            'vigente_desde' => '2026-07-01',
            'vigente_hasta' => null,
        ]);

        $this->getJson("/api/v1/precios-historicos?producto_id={$producto->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.precio', 12000);
    }

    public function test_post_precios_vigentes_batch(): void
    {
        $productoA = $this->createProducto('Producto A');
        $productoB = $this->createProducto('Producto B');

        PrecioHistorico::query()->create([
            'producto_id' => $productoA->id,
            'precio' => 5000,
            'vigente_desde' => '2026-01-01',
            'vigente_hasta' => null,
        ]);

        $this->postJson('/api/v1/precios-historicos/vigentes', [
            'producto_ids' => [$productoA->id, $productoB->id],
            'fecha' => '2026-07-01',
        ])
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['producto_id' => $productoA->id, 'precio' => 5000])
            ->assertJsonFragment(['producto_id' => $productoB->id, 'precio' => null]);
    }
}
