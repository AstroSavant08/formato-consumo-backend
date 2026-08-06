<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Inventario;
use App\Models\Producto;
use App\Models\Role;
use App\Models\Solicitud;
use App\Models\User;
use App\Support\TextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SolicitudRbacTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autoAuthenticateApi = false;

    private function createArea(): Area
    {
        return Area::query()->create([
            'codigo' => TextNormalizer::normalize('MANTENIMIENTO'),
            'nombre' => 'MANTENIMIENTO',
            'activo' => true,
        ]);
    }

    private function createProducto(): Producto
    {
        return Producto::query()->create([
            'nombre' => 'Producto RBAC',
            'nombre_normalizado' => TextNormalizer::normalize('Producto RBAC'),
            'unidad_default' => 'UND',
            'stock_minimo_referencia' => 10,
            'activo' => true,
            'es_historico_excel' => false,
        ]);
    }

    private function createInventario(Producto $producto, float $stockFisico = 100): Inventario
    {
        return Inventario::query()->create([
            'producto_id' => $producto->id,
            'stock_fisico' => $stockFisico,
            'stock_reserva' => 0,
            'stock_comprometido' => 0,
            'stock_minimo' => 10,
        ]);
    }

    private function solicitudPayload(Area $area, Producto $producto): array
    {
        return [
            'area_id' => $area->id,
            'fecha' => '2026-07-27',
            'justificacion' => 'Prueba RBAC',
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    'cantidad' => 2,
                    'unidad' => 'UND',
                    'precio_unitario' => 0,
                ],
            ],
        ];
    }

    private function crearSolicitudEnRevision(User $user, Area $area, Producto $producto): int
    {
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/solicitudes', $this->solicitudPayload($area, $producto));
        $solicitudId = (int) $response->json('data.id');

        $this->patchJson("/api/v1/solicitudes/{$solicitudId}", [
            'estado' => Solicitud::ESTADO_EN_REVISION,
        ])->assertOk();

        return $solicitudId;
    }

    public function test_solicitante_puede_crear_solicitud(): void
    {
        $user = $this->createUserWithRole(Role::SOLICITANTE);
        $area = $this->createArea();
        $producto = $this->createProducto();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/solicitudes', $this->solicitudPayload($area, $producto))
            ->assertCreated();
    }

    public function test_almacen_no_puede_crear_solicitud(): void
    {
        $user = $this->createUserWithRole(Role::ALMACEN);
        $area = $this->createArea();
        $producto = $this->createProducto();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/solicitudes', $this->solicitudPayload($area, $producto))
            ->assertForbidden()
            ->assertJsonPath('message', 'No tiene permiso para esta acción.');
    }

    public function test_solicitante_no_puede_aprobar_solicitud(): void
    {
        $solicitante = $this->createUserWithRole(Role::SOLICITANTE);
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto);

        $solicitudId = $this->crearSolicitudEnRevision($solicitante, $area, $producto);

        Sanctum::actingAs($solicitante);
        $this->postJson("/api/v1/solicitudes/{$solicitudId}/aprobar")
            ->assertForbidden()
            ->assertJsonPath('message', 'No tiene permiso para esta acción.');
    }

    public function test_supervisor_puede_aprobar_solicitud(): void
    {
        $solicitante = $this->createUserWithRole(Role::SOLICITANTE);
        $supervisor = $this->createUserWithRole(Role::SUPERVISOR);
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto);

        $solicitudId = $this->crearSolicitudEnRevision($solicitante, $area, $producto);

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/v1/solicitudes/{$solicitudId}/aprobar")
            ->assertOk()
            ->assertJsonPath('data.estado', Solicitud::ESTADO_APROBADA);
    }

    public function test_admin_puede_rechazar_solicitud(): void
    {
        $solicitante = $this->createUserWithRole(Role::SOLICITANTE);
        $admin = $this->createUserWithRole(Role::ADMIN);
        $area = $this->createArea();
        $producto = $this->createProducto();

        $solicitudId = $this->crearSolicitudEnRevision($solicitante, $area, $producto);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/solicitudes/{$solicitudId}/rechazar")
            ->assertOk()
            ->assertJsonPath('data.estado', Solicitud::ESTADO_RECHAZADA);
    }

    public function test_me_incluye_rol(): void
    {
        $user = $this->createUserWithRole(Role::SUPERVISOR);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role.nombre', Role::SUPERVISOR);
    }
}
