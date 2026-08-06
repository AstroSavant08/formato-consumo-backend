<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Entrega;
use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Models\Inventario;
use App\Models\Producto;
use App\Models\User;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlertaApiTest extends TestCase
{
    use RefreshDatabase;

    private function createProducto(string $nombre = 'Producto alerta api'): Producto
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

    private function createAlerta(
        Producto $producto,
        bool $leida = false,
        string $tipo = 'stock_minimo',
    ): Alerta {
        return Alerta::query()->create([
            'tipo' => $tipo,
            'severidad' => 'amarillo',
            'producto_id' => $producto->id,
            'area_id' => null,
            'mensaje' => 'El stock disponible (10.00) está por debajo del mínimo configurado (20.00).',
            'metadata' => [
                'stock_fisico' => 10,
                'stock_reserva' => 0,
                'stock_comprometido' => 0,
                'stock_disponible' => 10,
                'stock_minimo' => 20,
            ],
            'leida' => $leida,
        ]);
    }

    public function test_get_alertas_returns_empty_list(): void
    {
        $this->getJson('/api/v1/alertas')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_get_alertas_lists_existing_alerts(): void
    {
        $producto = $this->createProducto();
        $this->createAlerta($producto, false);

        $this->getJson('/api/v1/alertas')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tipo', 'stock_minimo')
            ->assertJsonPath('data.0.producto_id', $producto->id)
            ->assertJsonPath('data.0.leida', false);
    }

    public function test_get_alertas_filters_by_producto_id(): void
    {
        $productoA = $this->createProducto('Producto A alerta');
        $productoB = $this->createProducto('Producto B alerta');
        $this->createAlerta($productoA);
        $this->createAlerta($productoB);

        $this->getJson("/api/v1/alertas?producto_id={$productoA->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.producto_id', $productoA->id);
    }

    public function test_get_alertas_filters_by_tipo(): void
    {
        $producto = $this->createProducto();
        $this->createAlerta($producto, false, 'stock_minimo');

        $this->getJson('/api/v1/alertas?tipo=stock_minimo')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tipo', 'stock_minimo');

        $this->getJson('/api/v1/alertas?tipo=invalido')
            ->assertStatus(422);
    }

    public function test_get_alertas_filters_by_leida(): void
    {
        $producto = $this->createProducto();
        $this->createAlerta($producto, false);
        $this->createAlerta($producto, true);

        $this->getJson('/api/v1/alertas?leida=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.leida', false);

        $this->getJson('/api/v1/alertas?leida=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.leida', true);
    }

    public function test_get_alertas_is_read_only_and_does_not_modify_records(): void
    {
        $producto = $this->createProducto();
        $alerta = $this->createAlerta($producto, false);

        $this->getJson('/api/v1/alertas')->assertOk();
        $this->getJson("/api/v1/alertas?producto_id={$producto->id}&tipo=stock_minimo&leida=0")->assertOk();

        $alerta->refresh();
        $this->assertFalse($alerta->leida);
        $this->assertSame(1, Alerta::count());
    }

    public function test_get_alertas_does_not_modify_block4_records(): void
    {
        $area = \App\Models\Area::query()->create([
            'codigo' => TextNormalizer::normalize('MANTENIMIENTO'),
            'nombre' => 'MANTENIMIENTO',
            'activo' => true,
        ]);
        $productoHistorico = $this->createProducto('Producto histórico alertas api');

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
            'excel_hash' => hash('sha256', 'alerta-api-bloque4'),
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

        $productoOperativo = $this->createProducto('Producto operativo alertas api');
        Inventario::query()->create([
            'producto_id' => $productoOperativo->id,
            'stock_fisico' => 10,
            'stock_reserva' => 0,
            'stock_comprometido' => 0,
            'stock_minimo' => 20,
        ]);
        $this->createAlerta($productoOperativo, false);

        $this->getJson('/api/v1/alertas')->assertOk();

        $this->assertSame(1, Entrega::where('fuente', 'excel_historico')->count());
        $this->assertSame(1, ExcelImportHomologacion::count());
        $this->assertSame('UND', ExcelImportStaging::query()->find($staging->id)->unidad_raw);
        $this->assertSame(0, Inventario::where('producto_id', $productoHistorico->id)->count());
    }

    public function test_patch_alerta_requires_authentication(): void
    {
        $this->clearAuthentication();
        $producto = $this->createProducto();
        $alerta = $this->createAlerta($producto, false);

        $this->patchJson("/api/v1/alertas/{$alerta->id}", ['leida' => true])
            ->assertUnauthorized();

        $alerta->refresh();
        $this->assertFalse($alerta->leida);
    }

    public function test_patch_alerta_marks_as_read(): void
    {
        $user = $this->createUserWithRole(\App\Models\Role::SUPERVISOR);
        Sanctum::actingAs($user);

        $producto = $this->createProducto();
        $alerta = $this->createAlerta($producto, false);

        $this->patchJson("/api/v1/alertas/{$alerta->id}", ['leida' => true])
            ->assertOk()
            ->assertJsonPath('data.id', $alerta->id)
            ->assertJsonPath('data.leida', true)
            ->assertJsonPath('message', 'Alerta marcada como leída.');

        $alerta->refresh();
        $this->assertTrue($alerta->leida);
    }

    public function test_patch_alerta_can_mark_as_unread(): void
    {
        $user = $this->createUserWithRole(\App\Models\Role::SUPERVISOR);
        Sanctum::actingAs($user);

        $producto = $this->createProducto();
        $alerta = $this->createAlerta($producto, true);

        $this->patchJson("/api/v1/alertas/{$alerta->id}", ['leida' => false])
            ->assertOk()
            ->assertJsonPath('data.leida', false);

        $alerta->refresh();
        $this->assertFalse($alerta->leida);
    }

    public function test_patch_alerta_validates_leida_field(): void
    {
        $user = $this->createUserWithRole(\App\Models\Role::SUPERVISOR);
        Sanctum::actingAs($user);

        $producto = $this->createProducto();
        $alerta = $this->createAlerta($producto, false);

        $this->patchJson("/api/v1/alertas/{$alerta->id}", [])
            ->assertStatus(422);
    }
}
