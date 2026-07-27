<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Models\Inventario;
use App\Models\Producto;
use App\Services\ExcelImportService;
use App\Services\StagingHomologacionService;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StagingPromoteSelectedTest extends TestCase
{
    use RefreshDatabase;

    private ?Area $defaultArea = null;

    private function ensureArea(string $codigo = 'MANTENIMIENTO'): Area
    {
        if ($this->defaultArea === null) {
            $this->defaultArea = $this->createArea($codigo);
        }

        return $this->defaultArea;
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
            'es_posible_duplicado' => false,
        ], $overrides));
    }

    private function prepareValidatedStaging(array $overrides = []): ExcelImportStaging
    {
        $this->ensureArea();
        $staging = $this->createStaging($overrides);
        $producto = $this->createProductoOperativo(
            'Producto promovido '.($overrides['excel_hash'] ?? (string) $staging->id),
        );

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $producto->id,
            null,
            'Marcela',
        );

        app(ExcelImportService::class)->validateSelectedStaging([$staging->id]);

        return $staging->fresh();
    }

    private function promotePayload(array $stagingIds): array
    {
        return [
            'staging_ids' => $stagingIds,
            'confirmar_promocion' => true,
        ];
    }

    public function test_promote_selected_promotes_only_requested_ids(): void
    {
        $selected = $this->prepareValidatedStaging([
            'fila_excel' => 20,
            'excel_hash' => hash('sha256', 'promote-selected-1'),
        ]);
        $other = $this->prepareValidatedStaging([
            'fila_excel' => 21,
            'excel_hash' => hash('sha256', 'promote-other-1'),
        ]);

        $report = app(ExcelImportService::class)->promoteSelectedStaging([$selected->id], true);

        $selected->refresh();
        $other->refresh();

        $this->assertCount(1, $report['promovidos']);
        $this->assertSame('importado', $selected->estado);
        $this->assertSame('validado', $other->estado);
        $this->assertSame(1, Entrega::count());
    }

    public function test_other_validated_records_do_not_change(): void
    {
        $selected = $this->prepareValidatedStaging([
            'fila_excel' => 30,
            'excel_hash' => hash('sha256', 'promote-selected-2'),
        ]);
        $other = $this->prepareValidatedStaging([
            'fila_excel' => 31,
            'excel_hash' => hash('sha256', 'promote-other-2'),
        ]);

        $this->postJson('/api/v1/staging/promote-selected', $this->promotePayload([$selected->id]))
            ->assertOk();

        $other->refresh();

        $this->assertSame('validado', $other->estado);
        $this->assertNull(Entrega::query()->where('staging_id', $other->id)->first());
    }

    public function test_promote_selected_creates_entrega_with_excel_historico_source(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 40,
            'excel_hash' => hash('sha256', 'promote-fuente'),
        ]);

        app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertDatabaseHas('entregas', [
            'staging_id' => $staging->id,
            'fuente' => 'excel_historico',
        ]);
    }

    public function test_promote_selected_links_staging_id_correctly(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 50,
            'excel_hash' => hash('sha256', 'promote-link'),
        ]);

        $report = app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $entrega = Entrega::query()->find($report['promovidos'][0]['entrega_id']);

        $this->assertNotNull($entrega);
        $this->assertSame($staging->id, $entrega->staging_id);
    }

    public function test_promote_selected_changes_staging_from_validado_to_importado(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 60,
            'excel_hash' => hash('sha256', 'promote-estado'),
        ]);

        app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertSame('importado', $staging->fresh()->estado);
    }

    public function test_promote_selected_without_confirmar_promocion_returns_422(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 70,
            'excel_hash' => hash('sha256', 'promote-no-confirm'),
        ]);

        $this->postJson('/api/v1/staging/promote-selected', [
            'staging_ids' => [$staging->id],
        ])->assertStatus(422);
    }

    public function test_promote_selected_with_confirmar_promocion_false_returns_422(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 71,
            'excel_hash' => hash('sha256', 'promote-false-confirm'),
        ]);

        $this->postJson('/api/v1/staging/promote-selected', [
            'staging_ids' => [$staging->id],
            'confirmar_promocion' => false,
        ])->assertStatus(422);
    }

    public function test_promote_selected_without_confirmation_does_not_modify_anything(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 72,
            'excel_hash' => hash('sha256', 'promote-no-modify'),
        ]);

        try {
            app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], false);
            $this->fail('Se esperaba una excepción por falta de confirmación.');
        } catch (\App\Exceptions\StagingHomologacionException $exception) {
            $this->assertSame(422, $exception->status);
        }

        $staging->refresh();

        $this->assertSame('validado', $staging->estado);
        $this->assertSame(0, Entrega::count());
    }

    public function test_promote_selected_omits_requiere_revision(): void
    {
        $this->ensureArea();
        $staging = $this->createStaging([
            'fila_excel' => 80,
            'estado' => 'requiere_revision',
            'excel_hash' => hash('sha256', 'promote-requiere-revision'),
        ]);

        $report = app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertCount(1, $report['omitidos']);
        $this->assertSame('requiere_revision', $staging->fresh()->estado);
        $this->assertSame(0, Entrega::count());
    }

    public function test_promote_selected_omits_rechazado(): void
    {
        $this->ensureArea();
        $staging = $this->createStaging([
            'fila_excel' => 81,
            'estado' => 'rechazado',
            'excel_hash' => hash('sha256', 'promote-rechazado'),
        ]);

        $report = app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertCount(1, $report['omitidos']);
        $this->assertSame('rechazado', $staging->fresh()->estado);
    }

    public function test_promote_selected_omits_importado(): void
    {
        $this->ensureArea();
        $staging = $this->createStaging([
            'fila_excel' => 82,
            'estado' => 'importado',
            'excel_hash' => hash('sha256', 'promote-importado'),
        ]);

        $report = app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertCount(1, $report['omitidos']);
        $this->assertSame('importado', $staging->fresh()->estado);
    }

    public function test_promote_selected_omits_staging_without_producto_id(): void
    {
        $area = $this->ensureArea();
        $staging = $this->createStaging([
            'fila_excel' => 83,
            'estado' => 'validado',
            'producto_id' => null,
            'area_id' => $area->id,
            'excel_hash' => hash('sha256', 'promote-no-producto'),
        ]);

        $report = app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertCount(1, $report['omitidos']);
        $this->assertStringContainsString('producto', strtolower($report['omitidos'][0]['motivo']));
    }

    public function test_promote_selected_omits_staging_without_area_id(): void
    {
        $producto = $this->createProductoOperativo();
        $staging = $this->createStaging([
            'fila_excel' => 84,
            'estado' => 'validado',
            'producto_id' => $producto->id,
            'area_id' => null,
            'excel_hash' => hash('sha256', 'promote-no-area'),
        ]);

        $report = app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertCount(1, $report['omitidos']);
        $this->assertStringContainsString('área', strtolower($report['omitidos'][0]['motivo']));
    }

    public function test_promote_selected_omits_invalid_date(): void
    {
        $area = $this->ensureArea();
        $producto = $this->createProductoOperativo();
        $staging = $this->createStaging([
            'fila_excel' => 85,
            'estado' => 'validado',
            'fecha_raw' => 'fecha-invalida',
            'producto_id' => $producto->id,
            'area_id' => $area->id,
            'excel_hash' => hash('sha256', 'promote-fecha-invalida'),
        ]);

        $report = app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertCount(1, $report['omitidos']);
        $this->assertStringContainsString('fecha', strtolower($report['omitidos'][0]['motivo']));
    }

    public function test_promote_selected_omits_when_entrega_already_exists(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 86,
            'excel_hash' => hash('sha256', 'promote-duplicado'),
        ]);

        Entrega::query()->create([
            'fecha' => '2024-03-15',
            'area_id' => $staging->area_id,
            'producto_id' => $staging->producto_id,
            'cantidad' => 300,
            'unidad' => 'g',
            'quien_recibe' => 'Juan Perez',
            'entregado_por' => 'Marcela',
            'fuente' => 'excel_historico',
            'staging_id' => $staging->id,
            'excel_fila' => $staging->fila_excel,
            'excel_hash' => $staging->excel_hash,
            'es_posible_duplicado' => false,
        ]);

        $report = app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertCount(1, $report['omitidos']);
        $this->assertSame(1, Entrega::where('staging_id', $staging->id)->count());
        $this->assertSame('validado', $staging->fresh()->estado);
    }

    public function test_second_promotion_of_same_id_is_idempotent(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 87,
            'excel_hash' => hash('sha256', 'promote-idempotente'),
        ]);

        $first = app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);
        $second = app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertCount(1, $first['promovidos']);
        $this->assertCount(1, $second['omitidos']);
        $this->assertSame(1, Entrega::where('staging_id', $staging->id)->count());
    }

    public function test_promote_selected_preserves_raw_fields(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 88,
            'producto_raw' => 'AXION',
            'cantidad_raw' => '300',
            'unidad_raw' => 'g',
            'area_raw' => 'MANTENIMIENTO',
            'quien_recibe_raw' => 'Juan Perez',
            'entrega_raw' => 'Marcela',
            'fecha_raw' => '2024-03-15',
            'excel_hash' => hash('sha256', 'promote-raw-intact'),
        ]);

        app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $staging->refresh();

        $this->assertSame('AXION', $staging->producto_raw);
        $this->assertSame('300', $staging->cantidad_raw);
        $this->assertSame('g', $staging->unidad_raw);
        $this->assertSame('MANTENIMIENTO', $staging->area_raw);
        $this->assertSame('Juan Perez', $staging->quien_recibe_raw);
        $this->assertSame('Marcela', $staging->entrega_raw);
        $this->assertSame('2024-03-15', $staging->fecha_raw);
    }

    public function test_promote_selected_does_not_modify_homologaciones(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 89,
            'excel_hash' => hash('sha256', 'promote-homologacion'),
        ]);

        $homologacionBefore = ExcelImportHomologacion::query()
            ->where('staging_id', $staging->id)
            ->first()
            ?->toArray();

        app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $homologacionAfter = ExcelImportHomologacion::query()
            ->where('staging_id', $staging->id)
            ->first()
            ?->toArray();

        $this->assertSame($homologacionBefore, $homologacionAfter);
    }

    public function test_promote_selected_does_not_create_inventario(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 90,
            'excel_hash' => hash('sha256', 'promote-no-inventario-create'),
        ]);

        $inventarioCountBefore = Inventario::count();

        app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertSame($inventarioCountBefore, Inventario::count());
    }

    public function test_promote_selected_does_not_create_movimientos_inventario(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 91,
            'excel_hash' => hash('sha256', 'promote-no-movimientos'),
        ]);

        $movimientosBefore = DB::table('movimientos_inventario')->count();

        app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertSame($movimientosBefore, DB::table('movimientos_inventario')->count());
    }

    public function test_promote_selected_does_not_create_alertas(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 92,
            'excel_hash' => hash('sha256', 'promote-no-alertas'),
        ]);

        $alertasBefore = DB::table('alertas')->count();

        app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);

        $this->assertSame($alertasBefore, DB::table('alertas')->count());
    }

    public function test_promote_selected_maps_entrega_fields_correctly(): void
    {
        $hash = hash('sha256', 'promote-mapping');
        $this->createStaging([
            'fila_excel' => 62,
            'excel_hash' => $hash,
        ]);

        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 61,
            'fecha_raw' => '2024-03-15',
            'cantidad_raw' => '1000',
            'unidad_raw' => 'ML',
            'quien_recibe_raw' => 'Ana Lopez',
            'entrega_raw' => 'Pedro Ruiz',
            'excel_hash' => $hash,
        ]);

        $report = app(ExcelImportService::class)->promoteSelectedStaging([$staging->id], true);
        $entrega = Entrega::query()->find($report['promovidos'][0]['entrega_id']);
        $staging->refresh();

        $this->assertNotNull($entrega);
        $this->assertSame('2024-03-15', $entrega->fecha->toDateString());
        $this->assertSame($staging->area_id, $entrega->area_id);
        $this->assertSame($staging->producto_id, $entrega->producto_id);
        $this->assertSame(1000.0, (float) $entrega->cantidad);
        $this->assertSame('ML', $entrega->unidad);
        $this->assertSame('Ana Lopez', $entrega->quien_recibe);
        $this->assertSame('Pedro Ruiz', $entrega->entregado_por);
        $this->assertSame(61, $entrega->excel_fila);
        $this->assertSame($staging->excel_hash, $entrega->excel_hash);
        $this->assertTrue($staging->es_posible_duplicado);
        $this->assertSame($staging->es_posible_duplicado, $entrega->es_posible_duplicado);
        $this->assertSame($staging->id, $entrega->staging_id);
        $this->assertSame('excel_historico', $entrega->fuente);
    }

    public function test_promote_selected_endpoint_does_not_invoke_promote_validated(): void
    {
        $selected = $this->prepareValidatedStaging([
            'fila_excel' => 100,
            'excel_hash' => hash('sha256', 'promote-only-selected'),
        ]);
        $other = $this->prepareValidatedStaging([
            'fila_excel' => 101,
            'excel_hash' => hash('sha256', 'promote-untouched-other'),
        ]);

        $this->mock(ExcelImportService::class, function ($mock) use ($selected) {
            $mock->shouldReceive('promoteSelectedStaging')
                ->once()
                ->with([$selected->id], true)
                ->andReturn([
                    'promovidos' => [['staging_id' => $selected->id]],
                    'omitidos' => [],
                    'errores' => [],
                ]);
            $mock->shouldNotReceive('promoteValidated');
        });

        $this->postJson('/api/v1/staging/promote-selected', $this->promotePayload([$selected->id]))
            ->assertOk();

        $this->assertSame('validado', $other->fresh()->estado);
    }

    public function test_promote_selected_rolls_back_entire_batch_on_exception(): void
    {
        $stagingOne = $this->prepareValidatedStaging([
            'fila_excel' => 110,
            'excel_hash' => hash('sha256', 'promote-rollback-1'),
        ]);
        $stagingTwo = $this->prepareValidatedStaging([
            'fila_excel' => 111,
            'excel_hash' => hash('sha256', 'promote-rollback-2'),
        ]);

        ExcelImportStaging::updating(function (ExcelImportStaging $model) use ($stagingTwo) {
            if ($model->id === $stagingTwo->id) {
                throw new \RuntimeException('Fallo crítico simulado durante promoción controlada.');
            }
        });

        try {
            app(ExcelImportService::class)->promoteSelectedStaging([
                $stagingOne->id,
                $stagingTwo->id,
            ], true);
            $this->fail('Se esperaba una excepción crítica.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Fallo crítico simulado', $exception->getMessage());
        } finally {
            ExcelImportStaging::flushEventListeners();
        }

        $stagingOne->refresh();
        $stagingTwo->refresh();

        $this->assertSame('validado', $stagingOne->estado);
        $this->assertSame('validado', $stagingTwo->estado);
        $this->assertSame(0, Entrega::count());
    }

    public function test_promote_selected_endpoint_returns_structured_response(): void
    {
        $staging = $this->prepareValidatedStaging([
            'fila_excel' => 120,
            'excel_hash' => hash('sha256', 'promote-endpoint'),
        ]);

        $this->postJson('/api/v1/staging/promote-selected', $this->promotePayload([$staging->id]))
            ->assertOk()
            ->assertJsonPath('meta.promovidos', 1)
            ->assertJsonPath('meta.omitidos', 0)
            ->assertJsonPath('meta.errores', 0)
            ->assertJsonPath('data.promovidos.0.staging_id', $staging->id)
            ->assertJsonPath('data.promovidos.0.fila_excel', 120);
    }
}
