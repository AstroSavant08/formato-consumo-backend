<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Entrega;
use App\Models\ExcelImportStaging;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Solicitud;
use App\Models\User;
use App\Services\StagingHomologacionService;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SolicitudInventarioTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function createUser(): User
    {
        return User::factory()->create();
    }

    private function createArea(string $codigo = 'MANTENIMIENTO'): Area
    {
        return Area::query()->create([
            'codigo' => TextNormalizer::normalize($codigo),
            'nombre' => $codigo,
            'activo' => true,
        ]);
    }

    private function createProducto(string $nombre = 'Producto solicitud', ?string $unidadDefault = 'UND'): Producto
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

    private function createInventario(
        Producto $producto,
        float $stockFisico = 100,
        float $stockReserva = 0,
        float $stockComprometido = 0,
        float $stockMinimo = 10,
    ): Inventario {
        return Inventario::query()->create([
            'producto_id' => $producto->id,
            'stock_fisico' => $stockFisico,
            'stock_reserva' => $stockReserva,
            'stock_comprometido' => $stockComprometido,
            'stock_minimo' => $stockMinimo,
        ]);
    }

    private function solicitudPayload(Area $area, Producto $producto, float $cantidad = 10, string $unidad = 'UND'): array
    {
        return [
            'area_id' => $area->id,
            'fecha' => '2026-07-27',
            'justificacion' => 'Prueba Bloque 5.10',
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    'cantidad' => $cantidad,
                    'unidad' => $unidad,
                    'precio_unitario' => 0,
                ],
            ],
        ];
    }

    private function entregaPayload(Producto $producto, Area $area, float $cantidad = 10, string $unidad = 'UND', ?int $solicitudId = null): array
    {
        $payload = [
            'fecha' => '2026-07-27',
            'producto_id' => $producto->id,
            'area_id' => $area->id,
            'cantidad' => $cantidad,
            'unidad' => $unidad,
            'quien_recibe' => 'Receptor prueba',
            'entregado_por' => 'Entregador prueba',
        ];

        if ($solicitudId !== null) {
            $payload['solicitud_id'] = $solicitudId;
        }

        return $payload;
    }

    private function crearSolicitudEnRevision(User $user, Area $area, Producto $producto, float $cantidad = 10, string $unidad = 'UND'): int
    {
        $this->actingAsUser($user);
        $response = $this->postJson('/api/v1/solicitudes', $this->solicitudPayload($area, $producto, $cantidad, $unidad));
        $response->assertCreated();
        $id = (int) $response->json('data.id');

        $this->patchJson("/api/v1/solicitudes/{$id}", ['estado' => Solicitud::ESTADO_EN_REVISION])
            ->assertOk();

        return $id;
    }

    private function aprobarSolicitud(int $solicitudId, User $user): void
    {
        $this->actingAsUser($user);
        $this->postJson("/api/v1/solicitudes/{$solicitudId}/aprobar")
            ->assertOk();
    }

    public function test_crear_solicitud_valida_con_detalle(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto();

        $this->actingAsUser($user);
        $response = $this->postJson('/api/v1/solicitudes', $this->solicitudPayload($area, $producto));

        $response->assertCreated()
            ->assertJsonPath('data.estado', Solicitud::ESTADO_PENDIENTE)
            ->assertJsonPath('data.usuario_id', $user->id)
            ->assertJsonPath('data.detalles.0.producto_id', $producto->id)
            ->assertJsonPath('data.detalles.0.cantidad_solicitada', 10);

        $this->assertSame(1, Solicitud::count());
    }

    public function test_aprobar_incrementa_comprometido_sin_modificar_fisico(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto, 40);

        $solicitudId = $this->crearSolicitudEnRevision($user, $area, $producto, 10);
        $this->aprobarSolicitud($solicitudId, $user);

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();

        $this->assertSame('40.00', $inventario->stock_fisico);
        $this->assertSame('0.00', $inventario->stock_reserva);
        $this->assertSame('10.00', $inventario->stock_comprometido);
        $this->assertSame(30.0, $inventario->stock_disponible);
        $this->assertSame(Solicitud::ESTADO_APROBADA, Solicitud::query()->findOrFail($solicitudId)->estado);
    }

    public function test_aprobar_rechaza_stock_insuficiente_sin_cambios(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto, 40);

        $solicitudId = $this->crearSolicitudEnRevision($user, $area, $producto, 50);

        $this->actingAsUser($user);
        $this->postJson("/api/v1/solicitudes/{$solicitudId}/aprobar")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stock insuficiente para comprometer la solicitud.');

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('0.00', $inventario->stock_comprometido);
        $this->assertSame(Solicitud::ESTADO_EN_REVISION, Solicitud::query()->findOrFail($solicitudId)->estado);
    }

    public function test_cancelar_aprobada_libera_comprometido(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto, 40);

        $solicitudId = $this->crearSolicitudEnRevision($user, $area, $producto, 10);
        $this->aprobarSolicitud($solicitudId, $user);

        $this->actingAsUser($user);
        $this->postJson("/api/v1/solicitudes/{$solicitudId}/cancelar")->assertOk();

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('0.00', $inventario->stock_comprometido);
        $this->assertSame(40.0, $inventario->stock_disponible);
        $this->assertSame(Solicitud::ESTADO_CANCELADA, Solicitud::query()->findOrFail($solicitudId)->estado);
    }

    public function test_rechazar_en_revision_no_modifica_comprometido(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto, 40);

        $solicitudId = $this->crearSolicitudEnRevision($user, $area, $producto, 10);

        $this->actingAsUser($user);
        $this->postJson("/api/v1/solicitudes/{$solicitudId}/rechazar")->assertOk();

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('0.00', $inventario->stock_comprometido);
        $this->assertSame(Solicitud::ESTADO_RECHAZADA, Solicitud::query()->findOrFail($solicitudId)->estado);
    }

    public function test_entrega_vinculada_libera_comprometido_y_descuenta_fisico(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto, 40);

        $solicitudId = $this->crearSolicitudEnRevision($user, $area, $producto, 10);
        $this->aprobarSolicitud($solicitudId, $user);

        $response = $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 10, 'UND', $solicitudId));

        $response->assertCreated()
            ->assertJsonPath('data.solicitud_id', $solicitudId);

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('30.00', $inventario->stock_fisico);
        $this->assertSame('0.00', $inventario->stock_comprometido);
        $this->assertSame(30.0, $inventario->stock_disponible);

        $this->assertSame(1, MovimientoInventario::query()->where('tipo', 'entrega')->count());
        $this->assertSame(Solicitud::ESTADO_ENTREGADA, Solicitud::query()->findOrFail($solicitudId)->estado);
    }

    public function test_entrega_vinculada_rechaza_cantidad_superior_al_comprometido(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto, 40);

        $solicitudId = $this->crearSolicitudEnRevision($user, $area, $producto, 10);
        $this->aprobarSolicitud($solicitudId, $user);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 11, 'UND', $solicitudId))
            ->assertStatus(422);

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('40.00', $inventario->stock_fisico);
        $this->assertSame('10.00', $inventario->stock_comprometido);
        $this->assertSame(0, Entrega::query()->where('solicitud_id', $solicitudId)->count());
    }

    public function test_entrega_sin_solicitud_conserva_comportamiento_actual(): void
    {
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto, 80);

        $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 20))
            ->assertCreated();

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('60.00', $inventario->stock_fisico);
        $this->assertSame('0.00', $inventario->stock_comprometido);
    }

    public function test_respeta_reserva_administrativa_al_aprobar(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto, 100, 10);

        $solicitudId = $this->crearSolicitudEnRevision($user, $area, $producto, 85);
        $this->aprobarSolicitud($solicitudId, $user);

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('85.00', $inventario->stock_comprometido);
        $this->assertSame(5.0, $inventario->stock_disponible);

        $solicitudId2 = $this->crearSolicitudEnRevision($user, $area, $producto, 6);
        $this->actingAsUser($user);
        $this->postJson("/api/v1/solicitudes/{$solicitudId2}/aprobar")
            ->assertStatus(422);
    }

    public function test_conversion_ml_a_l_al_aprobar(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto('Liquido prueba', 'L');
        $this->createInventario($producto, 10);

        $solicitudId = $this->crearSolicitudEnRevision($user, $area, $producto, 500, 'ML');
        $this->aprobarSolicitud($solicitudId, $user);

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('0.50', $inventario->stock_comprometido);
        $this->assertSame(9.5, $inventario->stock_disponible);
    }

    public function test_conversion_g_a_kg_al_aprobar(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto('Solido prueba', 'KG');
        $this->createInventario($producto, 5);

        $solicitudId = $this->crearSolicitudEnRevision($user, $area, $producto, 1500, 'G');
        $this->aprobarSolicitud($solicitudId, $user);

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('1.50', $inventario->stock_comprometido);
    }

    public function test_unidad_incompatible_retorna_422_sin_efectos(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto('Producto L', 'L');
        $this->createInventario($producto, 10);

        $this->actingAsUser($user);
        $this->postJson('/api/v1/solicitudes', $this->solicitudPayload($area, $producto, 5, 'UND'))
            ->assertStatus(422);

        $this->assertSame(0, Solicitud::count());
    }

    public function test_solicitud_multilinea_compromete_por_producto(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $productoA = $this->createProducto('Producto A');
        $productoB = $this->createProducto('Producto B');
        $this->createInventario($productoA, 50);
        $this->createInventario($productoB, 30);

        $this->actingAsUser($user);
        $response = $this->postJson('/api/v1/solicitudes', [
            'area_id' => $area->id,
            'fecha' => '2026-07-27',
            'detalles' => [
                ['producto_id' => $productoA->id, 'cantidad' => 5, 'unidad' => 'UND'],
                ['producto_id' => $productoB->id, 'cantidad' => 7, 'unidad' => 'UND'],
            ],
        ]);
        $response->assertCreated();
        $solicitudId = (int) $response->json('data.id');

        $this->patchJson("/api/v1/solicitudes/{$solicitudId}", ['estado' => Solicitud::ESTADO_EN_REVISION])->assertOk();
        $this->aprobarSolicitud($solicitudId, $user);

        $this->assertSame('5.00', Inventario::query()->where('producto_id', $productoA->id)->value('stock_comprometido'));
        $this->assertSame('7.00', Inventario::query()->where('producto_id', $productoB->id)->value('stock_comprometido'));
    }

    public function test_atomicidad_aprobacion_revierte_compromisos_parciales(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $productoA = $this->createProducto('Producto atomic A');
        $productoB = $this->createProducto('Producto atomic B');
        $this->createInventario($productoA, 50);
        $this->createInventario($productoB, 5);

        $this->actingAsUser($user);
        $response = $this->postJson('/api/v1/solicitudes', [
            'area_id' => $area->id,
            'fecha' => '2026-07-27',
            'detalles' => [
                ['producto_id' => $productoA->id, 'cantidad' => 5, 'unidad' => 'UND'],
                ['producto_id' => $productoB->id, 'cantidad' => 10, 'unidad' => 'UND'],
            ],
        ]);
        $solicitudId = (int) $response->json('data.id');
        $this->patchJson("/api/v1/solicitudes/{$solicitudId}", ['estado' => Solicitud::ESTADO_EN_REVISION])->assertOk();

        $this->actingAsUser($user);
        $this->postJson("/api/v1/solicitudes/{$solicitudId}/aprobar")
            ->assertStatus(422);

        $this->assertSame('0.00', Inventario::query()->where('producto_id', $productoA->id)->value('stock_comprometido'));
        $this->assertSame('0.00', Inventario::query()->where('producto_id', $productoB->id)->value('stock_comprometido'));
        $this->assertSame(Solicitud::ESTADO_EN_REVISION, Solicitud::query()->findOrFail($solicitudId)->estado);
    }

    public function test_atomicidad_entrega_vinculada_revierte_cambios(): void
    {
        $user = $this->createUser();
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto, 40);

        $solicitudId = $this->crearSolicitudEnRevision($user, $area, $producto, 10);
        $this->aprobarSolicitud($solicitudId, $user);

        MovimientoInventario::creating(function () {
            throw new \RuntimeException('Fallo simulado durante entrega vinculada.');
        });

        try {
            $this->postJson('/api/v1/entregas', $this->entregaPayload($producto, $area, 10, 'UND', $solicitudId))
                ->assertStatus(500);
        } finally {
            MovimientoInventario::flushEventListeners();
        }

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('40.00', $inventario->stock_fisico);
        $this->assertSame('10.00', $inventario->stock_comprometido);
        $this->assertSame(0, Entrega::query()->where('solicitud_id', $solicitudId)->count());
        $this->assertSame(Solicitud::ESTADO_APROBADA, Solicitud::query()->findOrFail($solicitudId)->estado);
    }

    public function test_promocion_historica_no_afecta_comprometido(): void
    {
        $this->authenticateApiUser();
        $area = $this->createArea();
        $producto = $this->createProducto('Producto historico solicitud');
        $this->createInventario($producto, 100);

        $staging = ExcelImportStaging::query()->create([
            'fila_excel' => 501,
            'fecha_raw' => '2024-03-15',
            'producto_raw' => 'AXION',
            'cantidad_raw' => '300',
            'unidad_raw' => 'g',
            'area_raw' => 'MANTENIMIENTO',
            'quien_recibe_raw' => 'Juan Perez',
            'entrega_raw' => 'Marcela',
            'estado' => 'requiere_revision',
            'excel_hash' => hash('sha256', 'solicitud-historico-501'),
            'es_posible_duplicado' => false,
        ]);

        app(StagingHomologacionService::class)->homologar(
            $staging->id,
            $producto->id,
            'Manual',
            'Marcela',
        );

        $this->postJson('/api/v1/staging/validate-selected', ['staging_ids' => [$staging->id]])->assertOk();
        $this->postJson('/api/v1/staging/promote-selected', [
            'staging_ids' => [$staging->id],
            'confirmar_promocion' => true,
        ])->assertOk();

        $inventario = Inventario::query()->where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('100.00', $inventario->stock_fisico);
        $this->assertSame('0.00', $inventario->stock_comprometido);
        $this->assertSame(0, MovimientoInventario::query()->where('producto_id', $producto->id)->count());
    }
}
