<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Area;
use App\Models\ConfiguracionAlerta;
use App\Models\Entrega;
use App\Models\Producto;
use App\Support\TextNormalizer;
use Database\Seeders\ConfiguracionAlertaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemaforoConsumoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ConfiguracionAlertaSeeder::class);
    }

    private function createProducto(string $nombre = 'Cafe semaforo'): Producto
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

    private function createArea(string $codigo = 'CAFETERIA'): Area
    {
        return Area::query()->create([
            'codigo' => TextNormalizer::normalize($codigo),
            'nombre' => $codigo,
            'activo' => true,
        ]);
    }

    private function createEntrega(
        Producto $producto,
        string $fecha,
        float $cantidad,
        string $fuente = 'excel_historico',
        ?int $areaId = null,
    ): Entrega {
        $areaId ??= $this->createArea()->id;

        return Entrega::query()->create([
            'fecha' => $fecha,
            'area_id' => $areaId,
            'producto_id' => $producto->id,
            'cantidad' => $cantidad,
            'unidad' => 'UND',
            'fuente' => $fuente,
        ]);
    }

    private function seedHistoricoJulio(Producto $producto, float $cantidadPorAnio, ?int $areaId = null): void
    {
        $areaId ??= $this->createArea('HISTORICO')->id;

        foreach ([2023, 2024, 2025] as $anio) {
            $this->createEntrega($producto, "{$anio}-07-15", $cantidadPorAnio, 'excel_historico', $areaId);
        }
    }

    public function test_get_semaforo_verde_cuando_variacion_dentro_umbral(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea('OPERATIVA');
        $this->seedHistoricoJulio($producto, 100, $area->id);
        $this->createEntrega($producto, '2026-07-10', 110, 'sistema', $area->id);

        $this->getJson("/api/v1/semaforo/consumo?producto_id={$producto->id}&mes=7&anio=2026")
            ->assertOk()
            ->assertJsonPath('data.producto_id', $producto->id)
            ->assertJsonPath('data.consumo_actual', 110)
            ->assertJsonPath('data.promedio_historico', 100)
            ->assertJsonPath('data.variacion_porcentual', 10)
            ->assertJsonPath('data.severidad', 'verde');
    }

    public function test_get_semaforo_amarillo_cuando_variacion_moderada(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea('OPERATIVA');
        $this->seedHistoricoJulio($producto, 100, $area->id);
        $this->createEntrega($producto, '2026-07-10', 130, 'sistema', $area->id);

        $this->getJson("/api/v1/semaforo/consumo?producto_id={$producto->id}&mes=7&anio=2026")
            ->assertOk()
            ->assertJsonPath('data.variacion_porcentual', 30)
            ->assertJsonPath('data.severidad', 'amarillo');
    }

    public function test_get_semaforo_rojo_cuando_exceso_sobre_umbral(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea('OPERATIVA');
        $this->seedHistoricoJulio($producto, 100, $area->id);
        $this->createEntrega($producto, '2026-07-10', 150, 'sistema', $area->id);

        $this->getJson("/api/v1/semaforo/consumo?producto_id={$producto->id}&mes=7&anio=2026")
            ->assertOk()
            ->assertJsonPath('data.variacion_porcentual', 50)
            ->assertJsonPath('data.severidad', 'rojo');
    }

    public function test_get_semaforo_sin_datos_sin_historico(): void
    {
        $producto = $this->createProducto();
        $area = $this->createArea('OPERATIVA');
        $this->createEntrega($producto, '2026-07-10', 50, 'sistema', $area->id);

        $this->getJson("/api/v1/semaforo/consumo?producto_id={$producto->id}&mes=7&anio=2026")
            ->assertOk()
            ->assertJsonPath('data.promedio_historico', null)
            ->assertJsonPath('data.severidad', 'sin_datos');
    }

    public function test_get_semaforo_filtra_por_area(): void
    {
        $producto = $this->createProducto();
        $areaA = $this->createArea('AREA A');
        $areaB = $this->createArea('AREA B');

        foreach ([2023, 2024, 2025] as $anio) {
            $this->createEntrega($producto, "{$anio}-07-15", 100, 'excel_historico', $areaA->id);
            $this->createEntrega($producto, "{$anio}-07-15", 20, 'excel_historico', $areaB->id);
        }

        $this->createEntrega($producto, '2026-07-10', 110, 'sistema', $areaA->id);

        $this->getJson("/api/v1/semaforo/consumo?producto_id={$producto->id}&mes=7&anio=2026&area_id={$areaA->id}")
            ->assertOk()
            ->assertJsonPath('data.promedio_historico', 100)
            ->assertJsonPath('data.severidad', 'verde');
    }

    public function test_get_semaforo_validates_params(): void
    {
        $this->getJson('/api/v1/semaforo/consumo')
            ->assertStatus(422);
    }

    public function test_post_evaluar_crea_alerta_roja(): void
    {
        $this->authenticateApiUser();
        $producto = $this->createProducto();
        $area = $this->createArea('OPERATIVA');
        $this->seedHistoricoJulio($producto, 100, $area->id);
        $this->createEntrega($producto, '2026-07-10', 150, 'sistema', $area->id);

        $this->postJson("/api/v1/semaforo/consumo/evaluar?producto_id={$producto->id}&mes=7&anio=2026")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.severidad', 'rojo')
            ->assertJsonPath('data.alerta_creada', true)
            ->assertJsonPath('data.alerta.tipo', 'consumo_variacion')
            ->assertJsonPath('data.alerta.severidad', 'rojo');

        $this->assertSame(1, Alerta::query()->where('tipo', 'consumo_variacion')->count());
    }

    public function test_post_evaluar_no_crea_alerta_verde(): void
    {
        $this->authenticateApiUser();
        $producto = $this->createProducto();
        $area = $this->createArea('OPERATIVA');
        $this->seedHistoricoJulio($producto, 100, $area->id);
        $this->createEntrega($producto, '2026-07-10', 110, 'sistema', $area->id);

        $this->postJson("/api/v1/semaforo/consumo/evaluar?producto_id={$producto->id}&mes=7&anio=2026")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.severidad', 'verde')
            ->assertJsonPath('data.alerta', null)
            ->assertJsonPath('data.alerta_creada', false);

        $this->assertSame(0, Alerta::query()->count());
    }

    public function test_post_evaluar_requiere_autenticacion(): void
    {
        $this->clearAuthentication();
        $producto = $this->createProducto();

        $this->postJson("/api/v1/semaforo/consumo/evaluar?producto_id={$producto->id}&mes=7&anio=2026")
            ->assertUnauthorized();
    }

    public function test_post_evaluar_no_crea_alerta_si_config_inactiva(): void
    {
        $this->authenticateApiUser();
        ConfiguracionAlerta::query()
            ->where('clave', 'consumo_variacion_porcentual')
            ->update(['activo' => false]);

        $producto = $this->createProducto();
        $area = $this->createArea('OPERATIVA');
        $this->seedHistoricoJulio($producto, 100, $area->id);
        $this->createEntrega($producto, '2026-07-10', 150, 'sistema', $area->id);

        $this->postJson("/api/v1/semaforo/consumo/evaluar?producto_id={$producto->id}&mes=7&anio=2026")
            ->assertOk()
            ->assertJsonPath('data.evaluacion.severidad', 'rojo')
            ->assertJsonPath('data.alerta', null);

        $this->assertSame(0, Alerta::query()->count());
    }

    public function test_get_alertas_filtra_tipo_consumo_variacion(): void
    {
        $this->authenticateApiUser();
        $producto = $this->createProducto();
        $area = $this->createArea('OPERATIVA');
        $this->seedHistoricoJulio($producto, 100, $area->id);
        $this->createEntrega($producto, '2026-07-10', 150, 'sistema', $area->id);

        $this->postJson("/api/v1/semaforo/consumo/evaluar?producto_id={$producto->id}&mes=7&anio=2026")
            ->assertOk();

        $this->getJson('/api/v1/alertas?tipo=consumo_variacion')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tipo', 'consumo_variacion');
    }
}
