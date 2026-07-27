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
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $overrides = []): User
    {
        return User::factory()->create($overrides);
    }

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
            'nombre' => 'Producto auth',
            'nombre_normalizado' => TextNormalizer::normalize('Producto auth'),
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
            'justificacion' => 'Prueba auth',
            'detalles' => [
                [
                    'producto_id' => $producto->id,
                    'cantidad' => 1,
                    'unidad' => 'UND',
                    'precio_unitario' => 0,
                ],
            ],
        ];
    }

    public function test_login_exitoso_devuelve_token_y_usuario(): void
    {
        $user = $this->createUser([
            'email' => 'auth@impadoc.test',
            'password' => 'secret123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'auth@impadoc.test',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'area_id', 'role_id'],
            ])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissing(['password']);
    }

    public function test_login_con_credenciales_incorrectas_devuelve_401(): void
    {
        $this->createUser([
            'email' => 'auth@impadoc.test',
            'password' => 'secret123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'auth@impadoc.test',
            'password' => 'incorrecta',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Credenciales inválidas.');
    }

    public function test_usuario_inactivo_no_puede_autenticarse(): void
    {
        $this->createUser([
            'email' => 'inactivo@impadoc.test',
            'password' => 'secret123',
            'activo' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactivo@impadoc.test',
            'password' => 'secret123',
        ])->assertForbidden()
            ->assertJsonPath('message', 'El usuario está inactivo.');
    }

    public function test_me_requiere_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_me_devuelve_usuario_autenticado(): void
    {
        $user = $this->createUser(['email' => 'me@impadoc.test']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'me@impadoc.test');
    }

    public function test_logout_revoca_token_actual(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_me_rechaza_token_revocado(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('api')->plainTextToken;

        PersonalAccessToken::query()->delete();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_solicitud_sin_token_devuelve_401(): void
    {
        $area = $this->createArea();
        $producto = $this->createProducto();

        $this->postJson('/api/v1/solicitudes', $this->solicitudPayload($area, $producto))
            ->assertUnauthorized();
    }

    public function test_solicitud_con_token_usa_auth_id(): void
    {
        $user = $this->createUserWithRole(Role::SOLICITANTE);
        $area = $this->createArea();
        $producto = $this->createProducto();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/solicitudes', $this->solicitudPayload($area, $producto));

        $response->assertCreated()
            ->assertJsonPath('data.usuario_id', $user->id);

        $this->assertSame($user->id, Solicitud::query()->value('usuario_id'));
    }

    public function test_usuario_id_en_body_no_puede_suplantar_al_autenticado(): void
    {
        $authenticated = $this->createUserWithRole(Role::SOLICITANTE, ['email' => 'real@impadoc.test']);
        $other = $this->createUserWithRole(Role::SOLICITANTE, ['email' => 'otro@impadoc.test']);
        $area = $this->createArea();
        $producto = $this->createProducto();
        Sanctum::actingAs($authenticated);

        $payload = $this->solicitudPayload($area, $producto);
        $payload['usuario_id'] = $other->id;

        $this->postJson('/api/v1/solicitudes', $payload)
            ->assertStatus(422);

        $this->assertSame(0, Solicitud::count());
    }

    public function test_aprobar_registra_usuario_autenticado_como_aprobado_por(): void
    {
        $solicitante = $this->createUserWithRole(Role::SOLICITANTE, ['email' => 'solicitante@impadoc.test']);
        $aprobador = $this->createUserWithRole(Role::SUPERVISOR, ['email' => 'aprobador@impadoc.test']);
        $area = $this->createArea();
        $producto = $this->createProducto();
        $this->createInventario($producto);

        Sanctum::actingAs($solicitante);
        $response = $this->postJson('/api/v1/solicitudes', $this->solicitudPayload($area, $producto));
        $solicitudId = (int) $response->json('data.id');

        Sanctum::actingAs($solicitante);
        $this->patchJson("/api/v1/solicitudes/{$solicitudId}", [
            'estado' => Solicitud::ESTADO_EN_REVISION,
        ])->assertOk();

        Sanctum::actingAs($aprobador);
        $this->postJson("/api/v1/solicitudes/{$solicitudId}/aprobar", [
            'aprobado_por' => $solicitante->id,
        ])->assertStatus(422);

        Sanctum::actingAs($aprobador);
        $this->postJson("/api/v1/solicitudes/{$solicitudId}/aprobar")
            ->assertOk()
            ->assertJsonPath('data.aprobado_por', $aprobador->id);
    }

    public function test_staging_escritura_requiere_autenticacion(): void
    {
        $this->postJson('/api/v1/staging/validate-selected', ['staging_ids' => [1]])
            ->assertUnauthorized();

        $user = $this->createUser();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/staging/validate-selected', ['staging_ids' => []])
            ->assertUnprocessable();
    }
}
