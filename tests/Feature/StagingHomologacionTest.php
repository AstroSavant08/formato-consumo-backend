<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Models\Producto;
use App\Models\ProductoAlias;
use App\Services\ExcelImportService;
use App\Services\StagingHomologacionService;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StagingHomologacionTest extends TestCase
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

    public function test_valid_homologacion_is_persisted(): void
    {
        $this->createArea();
        $staging = $this->createStaging();
        $productoDestino = $this->createProductoOperativo();

        $service = app(StagingHomologacionService::class);
        $homologacion = $service->homologar(
            $staging->id,
            $productoDestino->id,
            'AXION en mantenimiento = barra',
            'Marcela',
        );

        $this->assertDatabaseHas('excel_import_homologaciones', [
            'staging_id' => $staging->id,
            'producto_id_destino' => $productoDestino->id,
            'confirmado_por' => 'Marcela',
            'notas' => 'AXION en mantenimiento = barra',
        ]);

        $this->assertSame($productoDestino->id, $homologacion->producto_id_destino);
    }

    public function test_homologacion_does_not_create_entrega(): void
    {
        $this->createArea();
        $staging = $this->createStaging();
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            null,
            'Marcela',
        );

        $this->assertSame(0, Entrega::count());
    }

    public function test_homologacion_does_not_modify_raw_fields(): void
    {
        $this->createArea();
        $staging = $this->createStaging([
            'producto_raw' => 'AXION',
            'cantidad_raw' => '300',
            'unidad_raw' => 'g',
            'area_raw' => 'MANTENIMIENTO',
            'quien_recibe_raw' => 'Juan Perez',
            'entrega_raw' => 'Marcela',
            'fecha_raw' => '2024-03-15',
        ]);
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            'Notas',
            'Marcela',
        );

        $staging->refresh();

        $this->assertSame('AXION', $staging->producto_raw);
        $this->assertSame('300', $staging->cantidad_raw);
        $this->assertSame('g', $staging->unidad_raw);
        $this->assertSame('MANTENIMIENTO', $staging->area_raw);
        $this->assertSame('Juan Perez', $staging->quien_recibe_raw);
        $this->assertSame('Marcela', $staging->entrega_raw);
        $this->assertSame('2024-03-15', $staging->fecha_raw);
    }

    public function test_historic_product_cannot_be_destination(): void
    {
        $staging = $this->createStaging();
        $productoHistorico = $this->createProductoHistorico();

        $this->expectExceptionMessage('El producto destino no puede ser un producto histórico de Excel.');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoHistorico->id,
            null,
            'Marcela',
        );
    }

    public function test_inactive_product_cannot_be_destination(): void
    {
        $staging = $this->createStaging();
        $productoInactivo = Producto::query()->create([
            'nombre' => 'Producto inactivo',
            'nombre_normalizado' => TextNormalizer::normalize('Producto inactivo'),
            'activo' => false,
            'es_historico_excel' => false,
        ]);

        $this->expectExceptionMessage('El producto destino está inactivo.');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoInactivo->id,
            null,
            'Marcela',
        );
    }

    public function test_imported_staging_cannot_be_homologated(): void
    {
        $staging = $this->createStaging(['estado' => 'importado']);
        $productoDestino = $this->createProductoOperativo();

        $this->expectExceptionMessage('No se puede homologar un registro ya importado a entregas.');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            null,
            'Marcela',
        );
    }

    public function test_rejected_staging_cannot_be_homologated(): void
    {
        $staging = $this->createStaging(['estado' => 'rechazado']);
        $productoDestino = $this->createProductoOperativo();

        $this->expectExceptionMessage('No se puede homologar un registro rechazado.');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            null,
            'Marcela',
        );
    }

    public function test_validate_respects_manual_homologacion(): void
    {
        $this->createArea();
        $productoHistorico = $this->createProductoHistorico('AXION');
        $this->createPendingAxionAlias($productoHistorico);

        $staging = $this->createStaging();
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            'Homologacion manual',
            'Marcela',
        );

        app(ExcelImportService::class)->validateStaging();

        $staging->refresh();

        $this->assertSame('validado', $staging->estado);
        $this->assertSame($productoDestino->id, $staging->producto_id);
        $this->assertSame('AXION', $staging->producto_raw);
    }

    public function test_pending_global_alias_does_not_block_homologated_row(): void
    {
        $this->createArea();
        $productoHistorico = $this->createProductoHistorico('AXION');
        $this->createPendingAxionAlias($productoHistorico);

        $staging = $this->createStaging();
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            null,
            'Marcela',
        );

        app(ExcelImportService::class)->validateStaging();

        $staging->refresh();

        $this->assertSame('validado', $staging->estado);
        $this->assertSame($productoDestino->id, $staging->producto_id);
        $this->assertNull($staging->errores_json);
    }

    public function test_row_without_homologacion_keeps_existing_behavior(): void
    {
        $this->createArea();
        $productoHistorico = $this->createProductoHistorico('AXION');
        $this->createPendingAxionAlias($productoHistorico);

        $staging = $this->createStaging();

        app(ExcelImportService::class)->validateStaging();

        $staging->refresh();

        $this->assertSame('requiere_revision', $staging->estado);
        $this->assertSame($productoHistorico->id, $staging->producto_id);
        $this->assertContains(
            'Producto con alias pendiente de revisión humana',
            $staging->errores_json ?? []
        );
        $this->assertSame(0, ExcelImportHomologacion::count());
    }

    public function test_store_homologacion_endpoint_persists_without_creating_entrega(): void
    {
        $this->createArea();
        $staging = $this->createStaging();
        $productoDestino = $this->createProductoOperativo();

        $response = $this->postJson("/api/v1/staging/{$staging->id}/homologacion", [
            'producto_id_destino' => $productoDestino->id,
            'notas' => 'Decision manual',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.staging_id', $staging->id)
            ->assertJsonPath('data.producto_id_destino', $productoDestino->id)
            ->assertJsonPath('data.notas', 'Decision manual');

        $this->assertSame(0, Entrega::count());
    }

    public function test_show_homologacion_endpoint_returns_existing_record(): void
    {
        $this->createArea();
        $staging = $this->createStaging();
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            'Consulta',
            'Marcela',
        );

        $response = $this->getJson("/api/v1/staging/{$staging->id}/homologacion");

        $response->assertOk()
            ->assertJsonPath('data.staging_id', $staging->id)
            ->assertJsonPath('data.producto_destino.nombre', $productoDestino->nombre);
    }

    public function test_show_homologacion_endpoint_returns_404_when_missing(): void
    {
        $staging = $this->createStaging();

        $response = $this->getJson("/api/v1/staging/{$staging->id}/homologacion");

        $response->assertNotFound()
            ->assertJsonPath('data', null);
    }

    public function test_homologacion_can_be_updated_for_same_staging_row(): void
    {
        $this->createArea();
        $staging = $this->createStaging();
        $productoUno = $this->createProductoOperativo('Producto uno');
        $productoDos = $this->createProductoOperativo('Producto dos');

        $service = app(StagingHomologacionService::class);

        $service->homologar($staging->id, $productoUno->id, 'Primera', 'Marcela');
        $service->homologar($staging->id, $productoDos->id, 'Correccion', 'Marcela', true);

        $this->assertSame(1, ExcelImportHomologacion::count());

        $this->assertDatabaseHas('excel_import_homologaciones', [
            'staging_id' => $staging->id,
            'producto_id_destino' => $productoDos->id,
            'notas' => 'Correccion',
        ]);
    }

    public function test_revision_endpoint_returns_enriched_queue(): void
    {
        $this->createArea();
        $staging = $this->createStaging(['producto_raw' => 'AXION']);
        $productoDestino = $this->createProductoOperativo('Accion en barra - mantenimiento');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            'Manual',
            'Marcela',
        );

        $response = $this->getJson('/api/v1/staging/revision?estado=requiere_revision&producto=AXION&homologacion=con');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.producto_raw', 'AXION')
            ->assertJsonPath('data.0.tiene_homologacion', true)
            ->assertJsonPath('data.0.homologacion.producto_destino.nombre', 'Accion en barra - mantenimiento');
    }

    public function test_summary_includes_homologaciones_activas(): void
    {
        $this->createArea();
        $staging = $this->createStaging();
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoDestino->id,
            null,
            'Marcela',
        );

        $response = $this->getJson('/api/v1/staging/summary');

        $response->assertOk()
            ->assertJsonPath('data.homologaciones_activas', 1)
            ->assertJsonPath('data.validado', 0);
    }

    public function test_revision_filters_by_area_id_without_partial_false_positives(): void
    {
        $mantenimiento = $this->createArea('MANTENIMIENTO');
        $talentoHumano = Area::query()->create([
            'codigo' => TextNormalizer::normalize('TALENTO HUMANO'),
            'nombre' => 'TALENTO HUMANO',
            'activo' => true,
        ]);

        ExcelImportStaging::query()->create([
            'fila_excel' => 11,
            'fecha_raw' => '2024-03-15',
            'producto_raw' => 'AXION',
            'cantidad_raw' => '300',
            'unidad_raw' => 'g',
            'area_raw' => 'MANTENIMIENTO',
            'quien_recibe_raw' => 'Juan Perez',
            'entrega_raw' => 'Marcela',
            'estado' => 'requiere_revision',
            'excel_hash' => hash('sha256', 'mantenimiento-axion'),
            'area_id' => $mantenimiento->id,
        ]);

        ExcelImportStaging::query()->create([
            'fila_excel' => 12,
            'fecha_raw' => '2024-03-16',
            'producto_raw' => 'SERVILLETAS',
            'cantidad_raw' => '10',
            'unidad_raw' => 'UND',
            'area_raw' => 'TALENTO HUMANO',
            'quien_recibe_raw' => 'Ana',
            'entrega_raw' => 'Marcela',
            'estado' => 'requiere_revision',
            'excel_hash' => hash('sha256', 'talento-servilletas'),
            'area_id' => $talentoHumano->id,
        ]);

        $response = $this->getJson('/api/v1/staging/revision?area_id='.$mantenimiento->id);

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.area_raw', 'MANTENIMIENTO');
    }

    public function test_bulk_homologacion_persists_multiple_rows(): void
    {
        $this->createArea();
        $staging1 = $this->createStaging(['fila_excel' => 10, 'excel_hash' => hash('sha256', 'bulk-1')]);
        $staging2 = $this->createStaging(['fila_excel' => 11, 'excel_hash' => hash('sha256', 'bulk-2')]);
        $productoDestino = $this->createProductoOperativo();

        $report = app(StagingHomologacionService::class)->homologarBulk(
            [$staging1->id, $staging2->id],
            $productoDestino->id,
            'Homologación masiva',
            'Marcela',
        );

        $this->assertCount(2, $report['homologados']);
        $this->assertSame(0, count($report['omitidos']));
        $this->assertSame(0, count($report['errores']));
        $this->assertSame(2, ExcelImportHomologacion::count());
    }

    public function test_bulk_homologacion_uses_database_transaction(): void
    {
        $this->createArea();
        $staging1 = $this->createStaging(['fila_excel' => 20, 'excel_hash' => hash('sha256', 'bulk-tx-1')]);
        $staging2 = $this->createStaging(['fila_excel' => 21, 'excel_hash' => hash('sha256', 'bulk-tx-2')]);
        $productoDestino = $this->createProductoOperativo();

        $report = app(StagingHomologacionService::class)->homologarBulk(
            [$staging1->id, $staging2->id],
            $productoDestino->id,
            null,
            'Marcela',
        );

        $this->assertCount(2, $report['homologados']);
        $this->assertDatabaseHas('excel_import_homologaciones', [
            'staging_id' => $staging1->id,
            'producto_id_destino' => $productoDestino->id,
        ]);
        $this->assertDatabaseHas('excel_import_homologaciones', [
            'staging_id' => $staging2->id,
            'producto_id_destino' => $productoDestino->id,
        ]);
    }

    public function test_bulk_homologacion_does_not_create_entregas(): void
    {
        $this->createArea();
        $staging1 = $this->createStaging(['fila_excel' => 30, 'excel_hash' => hash('sha256', 'bulk-ent-1')]);
        $staging2 = $this->createStaging(['fila_excel' => 31, 'excel_hash' => hash('sha256', 'bulk-ent-2')]);
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologarBulk(
            [$staging1->id, $staging2->id],
            $productoDestino->id,
            null,
            'Marcela',
        );

        $this->assertSame(0, Entrega::count());
    }

    public function test_bulk_homologacion_does_not_modify_raw_fields(): void
    {
        $this->createArea();
        $staging = $this->createStaging([
            'fila_excel' => 40,
            'producto_raw' => 'AXION',
            'cantidad_raw' => '300',
            'unidad_raw' => 'g',
            'area_raw' => 'MANTENIMIENTO',
            'excel_hash' => hash('sha256', 'bulk-raw'),
        ]);
        $productoDestino = $this->createProductoOperativo();

        app(StagingHomologacionService::class)->homologarBulk(
            [$staging->id],
            $productoDestino->id,
            null,
            'Marcela',
        );

        $staging->refresh();

        $this->assertSame('AXION', $staging->producto_raw);
        $this->assertSame('300', $staging->cantidad_raw);
        $this->assertSame('g', $staging->unidad_raw);
        $this->assertSame('MANTENIMIENTO', $staging->area_raw);
    }

    public function test_bulk_homologacion_rejects_inactive_product(): void
    {
        $staging = $this->createStaging();
        $productoInactivo = Producto::query()->create([
            'nombre' => 'Producto inactivo bulk',
            'nombre_normalizado' => TextNormalizer::normalize('Producto inactivo bulk'),
            'activo' => false,
            'es_historico_excel' => false,
        ]);

        $this->expectExceptionMessage('El producto destino está inactivo.');

        app(StagingHomologacionService::class)->homologarBulk(
            [$staging->id],
            $productoInactivo->id,
            null,
            'Marcela',
        );
    }

    public function test_bulk_homologacion_rejects_historic_product(): void
    {
        $staging = $this->createStaging();
        $productoHistorico = $this->createProductoHistorico();

        $this->expectExceptionMessage('El producto destino no puede ser un producto histórico de Excel.');

        app(StagingHomologacionService::class)->homologarBulk(
            [$staging->id],
            $productoHistorico->id,
            null,
            'Marcela',
        );
    }

    public function test_bulk_homologacion_omits_imported_rows(): void
    {
        $this->createArea();
        $valid = $this->createStaging(['fila_excel' => 50, 'excel_hash' => hash('sha256', 'bulk-valid')]);
        $imported = $this->createStaging([
            'fila_excel' => 51,
            'estado' => 'importado',
            'excel_hash' => hash('sha256', 'bulk-imported'),
        ]);
        $productoDestino = $this->createProductoOperativo();

        $report = app(StagingHomologacionService::class)->homologarBulk(
            [$valid->id, $imported->id],
            $productoDestino->id,
            null,
            'Marcela',
        );

        $this->assertCount(1, $report['homologados']);
        $this->assertCount(1, $report['omitidos']);
        $this->assertSame($imported->id, $report['omitidos'][0]['staging_id']);
        $this->assertStringContainsString('importado', $report['omitidos'][0]['motivo']);
    }

    public function test_bulk_homologacion_omits_rejected_rows(): void
    {
        $this->createArea();
        $valid = $this->createStaging(['fila_excel' => 60, 'excel_hash' => hash('sha256', 'bulk-valid-rej')]);
        $rejected = $this->createStaging([
            'fila_excel' => 61,
            'estado' => 'rechazado',
            'excel_hash' => hash('sha256', 'bulk-rejected'),
        ]);
        $productoDestino = $this->createProductoOperativo();

        $report = app(StagingHomologacionService::class)->homologarBulk(
            [$valid->id, $rejected->id],
            $productoDestino->id,
            null,
            'Marcela',
        );

        $this->assertCount(1, $report['homologados']);
        $this->assertCount(1, $report['omitidos']);
        $this->assertSame($rejected->id, $report['omitidos'][0]['staging_id']);
        $this->assertStringContainsString('rechazado', $report['omitidos'][0]['motivo']);
    }

    public function test_bulk_homologacion_omits_existing_without_confirmar_reemplazo(): void
    {
        $this->createArea();
        $staging = $this->createStaging(['fila_excel' => 70, 'excel_hash' => hash('sha256', 'bulk-existing')]);
        $productoUno = $this->createProductoOperativo('Producto uno');
        $productoDos = $this->createProductoOperativo('Producto dos');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoUno->id,
            'Existente',
            'Marcela',
        );

        $report = app(StagingHomologacionService::class)->homologarBulk(
            [$staging->id],
            $productoDos->id,
            'Intento bulk',
            'Marcela',
            false,
        );

        $this->assertCount(0, $report['homologados']);
        $this->assertCount(1, $report['omitidos']);
        $this->assertStringContainsString('confirmación explícita', $report['omitidos'][0]['motivo']);

        $this->assertDatabaseHas('excel_import_homologaciones', [
            'staging_id' => $staging->id,
            'producto_id_destino' => $productoUno->id,
        ]);
    }

    public function test_bulk_homologacion_replaces_existing_with_confirmar_reemplazo(): void
    {
        $this->createArea();
        $staging = $this->createStaging(['fila_excel' => 80, 'excel_hash' => hash('sha256', 'bulk-replace')]);
        $productoUno = $this->createProductoOperativo('Producto uno');
        $productoDos = $this->createProductoOperativo('Producto dos');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoUno->id,
            'Existente',
            'Marcela',
        );

        $report = app(StagingHomologacionService::class)->homologarBulk(
            [$staging->id],
            $productoDos->id,
            'Reemplazo bulk',
            'Marcela',
            true,
        );

        $this->assertCount(1, $report['homologados']);
        $this->assertCount(0, $report['omitidos']);

        $this->assertDatabaseHas('excel_import_homologaciones', [
            'staging_id' => $staging->id,
            'producto_id_destino' => $productoDos->id,
            'notas' => 'Reemplazo bulk',
        ]);
    }

    public function test_bulk_homologacion_endpoint_returns_report(): void
    {
        $this->createArea();
        $staging1 = $this->createStaging(['fila_excel' => 90, 'excel_hash' => hash('sha256', 'bulk-api-1')]);
        $staging2 = $this->createStaging([
            'fila_excel' => 91,
            'estado' => 'rechazado',
            'excel_hash' => hash('sha256', 'bulk-api-rej'),
        ]);
        $productoDestino = $this->createProductoOperativo();

        $response = $this->postJson('/api/v1/staging/homologaciones/bulk', [
            'staging_ids' => [$staging1->id, $staging2->id],
            'producto_id_destino' => $productoDestino->id,
            'notas' => 'Bulk API',
        ]);

        $response->assertOk()
            ->assertJsonPath('meta.homologados', 1)
            ->assertJsonPath('meta.omitidos', 1)
            ->assertJsonPath('meta.errores', 0)
            ->assertJsonPath('data.homologados.0.staging_id', $staging1->id);

        $this->assertSame(0, Entrega::count());
    }

    public function test_bulk_homologacion_endpoint_rejects_invalid_product(): void
    {
        $staging = $this->createStaging();
        $productoHistorico = $this->createProductoHistorico();

        $response = $this->postJson('/api/v1/staging/homologaciones/bulk', [
            'staging_ids' => [$staging->id],
            'producto_id_destino' => $productoHistorico->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data', null);
    }

    public function test_bulk_homologacion_accepts_more_than_100_staging_ids(): void
    {
        $this->createArea();
        $productoDestino = $this->createProductoOperativo();
        $stagingIds = [];

        for ($index = 1; $index <= 101; $index++) {
            $stagingIds[] = $this->createStaging([
                'fila_excel' => 2000 + $index,
                'excel_hash' => hash('sha256', "bulk-limit-101-{$index}"),
            ])->id;
        }

        $response = $this->postJson('/api/v1/staging/homologaciones/bulk', [
            'staging_ids' => $stagingIds,
            'producto_id_destino' => $productoDestino->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('meta.homologados', 101)
            ->assertJsonPath('meta.omitidos', 0)
            ->assertJsonPath('meta.errores', 0);
    }

    public function test_bulk_homologacion_rejects_more_than_500_staging_ids(): void
    {
        $productoDestino = $this->createProductoOperativo();

        $response = $this->postJson('/api/v1/staging/homologaciones/bulk', [
            'staging_ids' => range(1, 501),
            'producto_id_destino' => $productoDestino->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data', null);
    }

    public function test_individual_homologacion_remains_unaffected(): void
    {
        $this->createArea();
        $staging = $this->createStaging(['fila_excel' => 100, 'excel_hash' => hash('sha256', 'individual-intact')]);
        $productoDestino = $this->createProductoOperativo();

        $response = $this->postJson("/api/v1/staging/{$staging->id}/homologacion", [
            'producto_id_destino' => $productoDestino->id,
            'notas' => 'Individual intacta',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.staging_id', $staging->id)
            ->assertJsonPath('data.notas', 'Individual intacta');
    }

    public function test_individual_replace_without_confirmar_reemplazo_is_rejected(): void
    {
        $this->createArea();
        $staging = $this->createStaging(['fila_excel' => 101, 'excel_hash' => hash('sha256', 'replace-no-flag')]);
        $productoUno = $this->createProductoOperativo('Producto uno');
        $productoDos = $this->createProductoOperativo('Producto dos');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoUno->id,
            'Primera homologacion',
            'Marcela',
        );

        $response = $this->postJson("/api/v1/staging/{$staging->id}/homologacion", [
            'producto_id_destino' => $productoDos->id,
            'notas' => 'Intento sin confirmacion',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data', null)
            ->assertJsonPath(
                'message',
                'Ya existe una homologación manual para este registro; se requiere confirmación explícita para reemplazarla.'
            );

        $this->assertDatabaseHas('excel_import_homologaciones', [
            'staging_id' => $staging->id,
            'producto_id_destino' => $productoUno->id,
            'notas' => 'Primera homologacion',
        ]);
    }

    public function test_individual_replace_with_confirmar_reemplazo_false_is_rejected(): void
    {
        $this->createArea();
        $staging = $this->createStaging(['fila_excel' => 102, 'excel_hash' => hash('sha256', 'replace-false')]);
        $productoUno = $this->createProductoOperativo('Producto uno');
        $productoDos = $this->createProductoOperativo('Producto dos');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoUno->id,
            'Primera homologacion',
            'Marcela',
        );

        $response = $this->postJson("/api/v1/staging/{$staging->id}/homologacion", [
            'producto_id_destino' => $productoDos->id,
            'notas' => 'Intento explicitamente rechazado',
            'confirmar_reemplazo' => false,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('excel_import_homologaciones', [
            'staging_id' => $staging->id,
            'producto_id_destino' => $productoUno->id,
            'notas' => 'Primera homologacion',
        ]);
    }

    public function test_individual_replace_with_confirmar_reemplazo_true_succeeds(): void
    {
        $this->createArea();
        $staging = $this->createStaging([
            'fila_excel' => 103,
            'producto_raw' => 'AXION',
            'cantidad_raw' => '300',
            'unidad_raw' => 'g',
            'area_raw' => 'MANTENIMIENTO',
            'excel_hash' => hash('sha256', 'replace-true'),
        ]);
        $productoUno = $this->createProductoOperativo('Producto uno');
        $productoDos = $this->createProductoOperativo('Producto dos');

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $productoUno->id,
            'Primera homologacion',
            'Marcela',
        );

        $response = $this->postJson("/api/v1/staging/{$staging->id}/homologacion", [
            'producto_id_destino' => $productoDos->id,
            'notas' => 'Reemplazo confirmado',
            'confirmar_reemplazo' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.producto_id_destino', $productoDos->id)
            ->assertJsonPath('data.notas', 'Reemplazo confirmado');

        $staging->refresh();

        $this->assertSame('AXION', $staging->producto_raw);
        $this->assertSame('300', $staging->cantidad_raw);
        $this->assertSame('g', $staging->unidad_raw);
        $this->assertSame('MANTENIMIENTO', $staging->area_raw);
        $this->assertSame(0, Entrega::count());
        $this->assertSame(0, ProductoAlias::count());
    }
}
