<?php

namespace App\Services;

use App\Exceptions\SolicitudException;
use App\Models\Entrega;
use App\Models\Producto;
use App\Models\Solicitud;
use App\Models\SolicitudDetalle;
use App\Support\UnidadConversionService;
use Illuminate\Support\Facades\DB;

class SolicitudService
{
    public function __construct(
        private readonly InventarioService $inventarioService,
        private readonly UnidadConversionService $unidadConversionService,
    ) {
    }

    /**
     * @param  array{
     *     area_id: int,
     *     fecha: string,
     *     justificacion?: string|null,
     *     observaciones?: string|null,
     *     detalles: list<array{
     *         producto_id: int,
     *         cantidad: float|int|string,
     *         unidad: string,
     *         precio_unitario?: float|int|string|null
     *     }>
     * }  $datos
     */
    public function crear(array $datos, int $usuarioId): Solicitud
    {
        if ($datos['detalles'] === []) {
            throw new SolicitudException('La solicitud debe incluir al menos un detalle de producto.');
        }

        return DB::transaction(function () use ($datos, $usuarioId) {
            $total = 0.0;
            $numero = $this->generarNumero();

            $solicitud = Solicitud::query()->create([
                'numero' => $numero,
                'area_id' => $datos['area_id'],
                'usuario_id' => $usuarioId,
                'fecha' => $datos['fecha'],
                'estado' => Solicitud::ESTADO_PENDIENTE,
                'justificacion' => $datos['justificacion'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'total' => 0,
            ]);

            foreach ($datos['detalles'] as $linea) {
                $producto = Producto::query()->find($linea['producto_id']);
                if (! $producto) {
                    throw new SolicitudException('Producto no encontrado.', 404);
                }

                $cantidad = (float) $linea['cantidad'];
                if ($cantidad <= 0) {
                    throw new SolicitudException('La cantidad solicitada debe ser mayor que cero.');
                }

                $this->unidadConversionService->resolverCantidadEnUnidadBase(
                    $producto,
                    $cantidad,
                    $linea['unidad'],
                );

                $precioUnitario = (float) ($linea['precio_unitario'] ?? 0);
                $subtotal = round($cantidad * $precioUnitario, 2);
                $total += $subtotal;

                SolicitudDetalle::query()->create([
                    'solicitud_id' => $solicitud->id,
                    'producto_id' => $producto->id,
                    'cantidad_solicitada' => $cantidad,
                    'unidad' => $linea['unidad'],
                    'precio_unitario' => $precioUnitario,
                    'subtotal' => $subtotal,
                ]);
            }

            $solicitud->update(['total' => round($total, 2)]);

            return $solicitud->fresh(['detalles.producto', 'area', 'usuario']);
        });
    }

    /**
     * @param  array{
     *     area_id?: int,
     *     fecha?: string,
     *     justificacion?: string|null,
     *     observaciones?: string|null,
     *     estado?: string,
     *     detalles?: list<array{
     *         producto_id: int,
     *         cantidad: float|int|string,
     *         unidad: string,
     *         precio_unitario?: float|int|string|null
     *     }>
     * }  $datos
     */
    public function actualizar(Solicitud $solicitud, array $datos): Solicitud
    {
        if (! $solicitud->puedeEditarse()) {
            throw new SolicitudException('La solicitud no puede modificarse en su estado actual.');
        }

        return DB::transaction(function () use ($solicitud, $datos) {
            if (isset($datos['estado'])) {
                $this->validarTransicionManual($solicitud->estado, $datos['estado']);
                $solicitud->estado = $datos['estado'];
            }

            if (isset($datos['area_id'])) {
                $solicitud->area_id = $datos['area_id'];
            }

            if (isset($datos['fecha'])) {
                $solicitud->fecha = $datos['fecha'];
            }

            if (array_key_exists('justificacion', $datos)) {
                $solicitud->justificacion = $datos['justificacion'];
            }

            if (array_key_exists('observaciones', $datos)) {
                $solicitud->observaciones = $datos['observaciones'];
            }

            if (isset($datos['detalles'])) {
                if ($datos['detalles'] === []) {
                    throw new SolicitudException('La solicitud debe incluir al menos un detalle de producto.');
                }

                $solicitud->detalles()->delete();
                $total = 0.0;

                foreach ($datos['detalles'] as $linea) {
                    $producto = Producto::query()->find($linea['producto_id']);
                    if (! $producto) {
                        throw new SolicitudException('Producto no encontrado.', 404);
                    }

                    $cantidad = (float) $linea['cantidad'];
                    if ($cantidad <= 0) {
                        throw new SolicitudException('La cantidad solicitada debe ser mayor que cero.');
                    }

                    $this->unidadConversionService->resolverCantidadEnUnidadBase(
                        $producto,
                        $cantidad,
                        $linea['unidad'],
                    );

                    $precioUnitario = (float) ($linea['precio_unitario'] ?? 0);
                    $subtotal = round($cantidad * $precioUnitario, 2);
                    $total += $subtotal;

                    SolicitudDetalle::query()->create([
                        'solicitud_id' => $solicitud->id,
                        'producto_id' => $producto->id,
                        'cantidad_solicitada' => $cantidad,
                        'unidad' => $linea['unidad'],
                        'precio_unitario' => $precioUnitario,
                        'subtotal' => $subtotal,
                    ]);
                }

                $solicitud->total = round($total, 2);
            }

            $solicitud->save();

            return $solicitud->fresh(['detalles.producto', 'area', 'usuario']);
        });
    }

    public function aprobar(Solicitud $solicitud, ?int $aprobadoPorId = null): Solicitud
    {
        if ($solicitud->estado !== Solicitud::ESTADO_EN_REVISION) {
            throw new SolicitudException('Solo se pueden aprobar solicitudes en revisión.');
        }

        $solicitud->load('detalles.producto');

        if ($solicitud->detalles->isEmpty()) {
            throw new SolicitudException('La solicitud no tiene detalles para aprobar.');
        }

        return DB::transaction(function () use ($solicitud, $aprobadoPorId) {
            $compromisosPorProducto = [];

            foreach ($solicitud->detalles as $detalle) {
                $producto = $detalle->producto;
                if (! $producto) {
                    throw new SolicitudException('Producto no encontrado.', 404);
                }

                $cantidadBase = $this->unidadConversionService->resolverCantidadEnUnidadBase(
                    $producto,
                    (float) $detalle->cantidad_solicitada,
                    $detalle->unidad,
                );

                $detalle->update(['cantidad_aprobada' => $cantidadBase]);

                $compromisosPorProducto[$producto->id] = ($compromisosPorProducto[$producto->id] ?? 0) + $cantidadBase;
            }

            foreach ($compromisosPorProducto as $productoId => $cantidadBase) {
                $this->inventarioService->comprometerStock((int) $productoId, $cantidadBase);
            }

            $solicitud->update([
                'estado' => Solicitud::ESTADO_APROBADA,
                'aprobado_por' => $aprobadoPorId,
                'aprobado_at' => now(),
            ]);

            return $solicitud->fresh(['detalles.producto', 'area', 'usuario', 'aprobadoPor']);
        });
    }

    public function rechazar(Solicitud $solicitud, ?int $rechazadoPorId = null): Solicitud
    {
        if ($solicitud->estado !== Solicitud::ESTADO_EN_REVISION) {
            throw new SolicitudException('Solo se pueden rechazar solicitudes en revisión.');
        }

        $solicitud->update([
            'estado' => Solicitud::ESTADO_RECHAZADA,
            'aprobado_por' => $rechazadoPorId,
            'aprobado_at' => now(),
        ]);

        return $solicitud->fresh(['detalles.producto', 'area', 'usuario', 'aprobadoPor']);
    }

    public function cancelar(Solicitud $solicitud): Solicitud
    {
        if (! in_array($solicitud->estado, [
            Solicitud::ESTADO_PENDIENTE,
            Solicitud::ESTADO_EN_REVISION,
            Solicitud::ESTADO_APROBADA,
        ], true)) {
            throw new SolicitudException('La solicitud no puede cancelarse en su estado actual.');
        }

        return DB::transaction(function () use ($solicitud) {
            if ($solicitud->estado === Solicitud::ESTADO_APROBADA) {
                $this->liberarCompromisoPendiente($solicitud);
            }

            $solicitud->update(['estado' => Solicitud::ESTADO_CANCELADA]);

            return $solicitud->fresh(['detalles.producto', 'area', 'usuario']);
        });
    }

    public function cantidadPendienteEntrega(Solicitud $solicitud, int $productoId): float
    {
        $solicitud->loadMissing('detalles.producto');

        $aprobada = 0.0;
        foreach ($solicitud->detalles->where('producto_id', $productoId) as $detalle) {
            $aprobada += (float) ($detalle->cantidad_aprobada ?? 0);
        }

        $entregada = $this->cantidadEntregadaEnBase($solicitud, $productoId);

        return max(0.0, round($aprobada - $entregada, 2));
    }

    public function todasLineasEntregadas(Solicitud $solicitud): bool
    {
        $solicitud->loadMissing('detalles.producto');

        foreach ($solicitud->detalles as $detalle) {
            if ($this->cantidadPendienteEntrega($solicitud, (int) $detalle->producto_id) > 0) {
                return false;
            }
        }

        return $solicitud->detalles->isNotEmpty();
    }

    public function marcarEntregadaSiCompleta(Solicitud $solicitud): Solicitud
    {
        if ($solicitud->estado !== Solicitud::ESTADO_APROBADA) {
            return $solicitud;
        }

        if ($this->todasLineasEntregadas($solicitud)) {
            $solicitud->update(['estado' => Solicitud::ESTADO_ENTREGADA]);
        }

        return $solicitud->fresh(['detalles.producto', 'area', 'usuario']);
    }

    public function validarEntregaVinculada(Solicitud $solicitud, int $productoId, float $cantidadBase): void
    {
        if ($solicitud->estado !== Solicitud::ESTADO_APROBADA) {
            throw new SolicitudException('La solicitud vinculada debe estar aprobada.');
        }

        $detalleExiste = $solicitud->detalles()->where('producto_id', $productoId)->exists();
        if (! $detalleExiste) {
            throw new SolicitudException('El producto no pertenece a la solicitud vinculada.');
        }

        $pendiente = $this->cantidadPendienteEntrega($solicitud, $productoId);
        if ($cantidadBase > $pendiente) {
            throw new SolicitudException(
                'La cantidad entregada excede el comprometido pendiente de la solicitud.',
                422,
                [
                    'cantidad_pendiente' => $pendiente,
                    'cantidad_entregada' => $cantidadBase,
                ]
            );
        }
    }

    private function liberarCompromisoPendiente(Solicitud $solicitud): void
    {
        $solicitud->loadMissing('detalles.producto');

        $liberacionesPorProducto = [];

        foreach ($solicitud->detalles as $detalle) {
            $pendiente = $this->cantidadPendienteEntrega($solicitud, (int) $detalle->producto_id);
            if ($pendiente <= 0) {
                continue;
            }

            $liberacionesPorProducto[$detalle->producto_id] =
                ($liberacionesPorProducto[$detalle->producto_id] ?? 0) + $pendiente;
        }

        foreach ($liberacionesPorProducto as $productoId => $cantidad) {
            $this->inventarioService->liberarCompromiso((int) $productoId, $cantidad);
        }
    }

    private function cantidadEntregadaEnBase(Solicitud $solicitud, int $productoId): float
    {
        $producto = Producto::query()->find($productoId);
        if (! $producto) {
            return 0.0;
        }

        $entregas = Entrega::query()
            ->where('solicitud_id', $solicitud->id)
            ->where('producto_id', $productoId)
            ->where('fuente', 'sistema')
            ->get();

        $total = 0.0;
        foreach ($entregas as $entrega) {
            $total += $this->unidadConversionService->resolverCantidadEnUnidadBase(
                $producto,
                (float) $entrega->cantidad,
                $entrega->unidad,
            );
        }

        return round($total, 2);
    }

    private function validarTransicionManual(string $estadoActual, string $estadoNuevo): void
    {
        $permitidas = match ($estadoActual) {
            Solicitud::ESTADO_PENDIENTE => [Solicitud::ESTADO_EN_REVISION],
            default => [],
        };

        if (! in_array($estadoNuevo, $permitidas, true)) {
            throw new SolicitudException('Transición de estado no permitida.');
        }
    }

    private function generarNumero(): string
    {
        $ultimoId = (int) Solicitud::query()->max('id');
        $secuencia = str_pad((string) ($ultimoId + 1), 6, '0', STR_PAD_LEFT);

        return 'SOL-'.now()->format('Y').'-'.$secuencia;
    }
}
