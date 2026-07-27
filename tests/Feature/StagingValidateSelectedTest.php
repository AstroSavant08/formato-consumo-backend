<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Models\Inventario;
use App\Models\Producto;
use App\Models\ProductoAlias;
use App\Services\ExcelImportService;
use App\Services\StagingHomologacionService;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StagingValidateSelectedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticateApiUser();
    }

    private function createArea(string $codigo = 'MANTENIMIENTO'): Area
    {
        return Area::query()->create([
            'codigo' => TextNormalizer::normalize($codigo),
            'nombre' => $codigo,
            'activo' => true,
        ]);
    }

    private function createProductoOperativo(string $nombre = 'Accion en barra - mantenimiento'): Producto
    {
        return Producto::query()->create([
            'nombre' => $nombre,
            'nombre_normalizado' => TextNormalizer::normalize($nombre),
            'activo' => true,
            'es_historico_excel' => false,
        ]);
    }

    private function createProductoHistorico(string $nombre = 'AXION'): Producto
    {
        return Producto::query()->create([
            'nombre' => $nombre,
            'nombre_normalizado' => TextNormalizer::normalize($nombre),
            'activo' => true,
            'es_historico_excel' => true,
        ]);
    }

    private function createStaging(array $overrides = []): ExcelImportStaging
    {
        return ExcelImportStaging::query()->create(array_merge([
            'fila_excel' => 10,
            'fecha_raw' => '2024-03-15',
            'producto_raw' => 'AXION',
            'cantidad_raw' => '300',
            'unidad_raw' => 'g',
            'area_raw' => 'MANTENIMIENTO',
            'quien_recibe_raw' => 'Juan Perez',
            'entrega_raw' => 'Marcela',
            'estado' => 'requiere_revision',
            'excel_hash' => hash('sha256', 'axion-mantenimiento-300'),
        ], $overrides));
    }

    private function createPendingAxionAlias(Producto $productoHistorico): ProductoAlias
    {
        return ProductoAlias::query()->create([
            'producto_id' => $productoHistorico->id,
            'alias' => 'AXION',
            'alias_normalizado' => TextNormalizer::normalize('AXION'),
            'fuente' => 'excel',
            'confianza' => 0,
            'revisado' => false,
            'requiere_revision' => true,
            'notas' => 'Alias pendiente de revision',
        ]);
    }

    public function test_validate_selected_validates_only_requested_ids(): void
    {
        $this->createArea();
        $selected = $this->createStaging(['fila_excel' => 20, 'excel_hash' => hash('sha256', 'selected-1')]);
        $other = $this->createStaging(['fila_excel' => 21, 'excel_hash' => hash('sha256', 'other-1')]);
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $selected->id,
            $productoDestino->id,
            'Manual',
            'Marcela',
        );

        $report = app(ExcelImportService::class)->validateSelectedStaging([$selected->id]);

        $selected->refresh();
        $other->refresh();

        $this->assertCount(1, $report['validados']);
        $this->assertSame('validado', $selected->estado);
        $this->assertSame($productoDestino->id, $selected->producto_id);
        $this->assertSame('requiere_revision', $other->estado);
        $this->assertNull($other->producto_id);
    }

    public function test_validate_selected_endpoint_returns_report(): void
    {
        $this->createArea();
        $staging = $this->createStaging(['fila_excel' => 30, 'excel_hash' => hash('sha256', 'endpoint-selected')]);
        $productoDestino = $this->createProductoOperativo('Producto destino');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            null,
            'Marcela',
        );

        $response = $this->postJson('/api/v1/staging/validate-selected', [
            'staging_ids' => [$staging->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('meta.validados', 1)
            ->assertJsonPath('meta.requieren_revision', 0)
            ->assertJsonPath('data.validados.0.staging_id', $staging->id)
            ->assertJsonPath('data.validados.0.producto_resuelto.nombre', 'Producto destino');
    }

    public function test_validate_selected_uses_manual_homologacion_priority_over_pending_alias(): void
    {
        $this->createArea();
        $productoHistorico = $this->createProductoHistorico('AXION');
        $this->createPendingAxionAlias($productoHistorico);

        $staging = $this->createStaging(['fila_excel' => 40, 'excel_hash' => hash('sha256', 'homolog-priority')]);
        $productoDestino = $this->createProductoOperativo('Producto operativo destino');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            'Prioridad homologacion',
            'Marcela',
        );

        $report = app(ExcelImportService::class)->validateSelectedStaging([$staging->id]);

        $staging->refresh();

        $this->assertCount(1, $report['validados']);
        $this->assertSame('validado', $staging->estado);
        $this->assertSame($productoDestino->id, $staging->producto_id);
        $this->assertNull($staging->errores_json);
    }

    public function test_validate_selected_does_not_modify_raw_fields(): void
    {
        $this->createArea();
        $staging = $this->createStaging([
            'fila_excel' => 50,
            'producto_raw' => 'AXION',
            'cantidad_raw' => '300',
            'unidad_raw' => 'g',
            'area_raw' => 'MANTENIMIENTO',
            'quien_recibe_raw' => 'Juan Perez',
            'entrega_raw' => 'Marcela',
            'fecha_raw' => '2024-03-15',
            'excel_hash' => hash('sha256', 'raw-intact'),
        ]);
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            null,
            'Marcela',
        );

        app(ExcelImportService::class)->validateSelectedStaging([$staging->id]);

        $staging->refresh();

        $this->assertSame('AXION', $staging->producto_raw);
        $this->assertSame('300', $staging->cantidad_raw);
        $this->assertSame('g', $staging->unidad_raw);
        $this->assertSame('MANTENIMIENTO', $staging->area_raw);
        $this->assertSame('Juan Perez', $staging->quien_recibe_raw);
        $this->assertSame('Marcela', $staging->entrega_raw);
        $this->assertSame('2024-03-15', $staging->fecha_raw);
    }

    public function test_validate_selected_does_not_create_entregas(): void
    {
        $this->createArea();
        $staging = $this->createStaging(['fila_excel' => 60, 'excel_hash' => hash('sha256', 'no-entrega')]);
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            null,
            'Marcela',
        );

        app(ExcelImportService::class)->validateSelectedStaging([$staging->id]);

        $this->assertSame(0, Entrega::count());
    }

    public function test_validate_selected_does_not_modify_inventario(): void
    {
        $this->createArea();
        $staging = $this->createStaging(['fila_excel' => 70, 'excel_hash' => hash('sha256', 'no-inventario')]);
        $productoDestino = $this->createProductoOperativo();

        Inventario::query()->create([
            'producto_id' => $productoDestino->id,
            'stock_fisico' => 100,
            'stock_reserva' => 0,
            'stock_minimo' => 10,
            'stock_comprometido' => 0,
        ]);

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            null,
            'Marcela',
        );

        app(ExcelImportService::class)->validateSelectedStaging([$staging->id]);

        $this->assertDatabaseHas('inventarios', [
            'producto_id' => $productoDestino->id,
            'stock_fisico' => 100,
            'stock_reserva' => 0,
            'stock_minimo' => 10,
            'stock_comprometido' => 0,
        ]);
    }

    public function test_validate_selected_omits_imported_and_rejected_rows(): void
    {
        $this->createArea();
        $valid = $this->createStaging(['fila_excel' => 80, 'excel_hash' => hash('sha256', 'valid-selected')]);
        $imported = $this->createStaging([
            'fila_excel' => 81,
            'estado' => 'importado',
            'excel_hash' => hash('sha256', 'imported-selected'),
        ]);
        $rejected = $this->createStaging([
            'fila_excel' => 82,
            'estado' => 'rechazado',
            'excel_hash' => hash('sha256', 'rejected-selected'),
        ]);
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $valid->id,
            $productoDestino->id,
            null,
            'Marcela',
        );

        $report = app(ExcelImportService::class)->validateSelectedStaging([
            $valid->id,
            $imported->id,
            $rejected->id,
        ]);

        $this->assertCount(1, $report['validados']);
        $this->assertCount(2, $report['omitidos']);
        $this->assertSame('importado', $imported->fresh()->estado);
        $this->assertSame('rechazado', $rejected->fresh()->estado);
    }

    public function test_validate_selected_rolls_back_entire_batch_on_critical_failure(): void
    {
        $this->createArea();
        $stagingOne = $this->createStaging(['fila_excel' => 90, 'excel_hash' => hash('sha256', 'rollback-1')]);
        $stagingTwo = $this->createStaging(['fila_excel' => 91, 'excel_hash' => hash('sha256', 'rollback-2')]);
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $stagingOne->id,
            $productoDestino->id,
            null,
            'Marcela',
        );
        app(StagingHomologacionService::class)->homologar(
            $stagingTwo->id,
            $productoDestino->id,
            null,
            'Marcela',
        );

        ExcelImportStaging::updating(function (ExcelImportStaging $model) use ($stagingTwo) {
            if ($model->id === $stagingTwo->id) {
                throw new \RuntimeException('Fallo crítico simulado durante validación controlada.');
            }
        });

        try {
            app(ExcelImportService::class)->validateSelectedStaging([
                $stagingOne->id,
                $stagingTwo->id,
            ]);
            $this->fail('Se esperaba una excepción crítica.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Fallo crítico simulado', $exception->getMessage());
        } finally {
            ExcelImportStaging::flushEventListeners();
        }

        $stagingOne->refresh();
        $stagingTwo->refresh();

        $this->assertSame('requiere_revision', $stagingOne->estado);
        $this->assertSame('requiere_revision', $stagingTwo->estado);
        $this->assertNull($stagingOne->producto_id);
        $this->assertNull($stagingTwo->producto_id);
    }

    public function test_global_validate_staging_is_not_invoked_by_selected_flow(): void
    {
        $this->createArea();
        $selected = $this->createStaging(['fila_excel' => 100, 'excel_hash' => hash('sha256', 'only-selected')]);
        $other = $this->createStaging(['fila_excel' => 101, 'excel_hash' => hash('sha256', 'untouched-other')]);
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $selected->id,
            $productoDestino->id,
            null,
            'Marcela',
        );

        $mock = $this->mock(ExcelImportService::class, function ($mock) use ($selected) {
            $mock->shouldReceive('validateSelectedStaging')
                ->once()
                ->with([$selected->id])
                ->andReturn([
                    'validados' => [['staging_id' => $selected->id]],
                    'requieren_revision' => [],
                    'omitidos' => [],
                    'errores' => [],
                ]);
            $mock->shouldNotReceive('validateStaging');
        });

        $this->postJson('/api/v1/staging/validate-selected', [
            'staging_ids' => [$selected->id],
        ])->assertOk();

        $other->refresh();
        $this->assertSame('requiere_revision', $other->estado);
    }
}
