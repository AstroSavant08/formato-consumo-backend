<?php

namespace App\Services;

use App\Exceptions\InventarioException;
use App\Models\Entrega;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Support\UnidadConversionService;
use Illuminate\Support\Facades\DB;

class InventarioService
{
    public function __construct(
        private readonly AlertaInventarioService $alertaInventarioService,
        private readonly UnidadConversionService $unidadConversionService,
    ) {
    }

    /**
     * Carga inicial administrativa sin movimiento de auditoría.
     * Las entradas posteriores sí generan movimientos tipo entrada.
     */
    public function crearInventarioInicial(
        int $productoId,
        float $stockInicial = 0,
        ?float $stockMinimo = null,
    ): Inventario {
        if ($stockInicial < 0) {
            throw new InventarioException('El stock inicial no puede ser negativo.');
        }

        $producto = Producto::query()->find($productoId);
        if (! $producto) {
            throw new InventarioException('Producto no encontrado.', 404);
        }

        if (Inventario::query()->where('producto_id', $productoId)->exists()) {
            throw new InventarioException('Ya existe inventario para este producto.');
        }

        $stockMinimoResuelto = $stockMinimo ?? (float) ($producto->stock_minimo_referencia ?? 0);
        if ($stockMinimoResuelto < 0) {
            throw new InventarioException('El stock mínimo no puede ser negativo.');
        }

        return Inventario::query()->create([
            'producto_id' => $productoId,
            'stock_fisico' => $stockInicial,
            'stock_reserva' => 0,
            'stock_minimo' => $stockMinimoResuelto,
            'stock_comprometido' => 0,
        ]);
    }

    public function registrarEntrada(
        int $productoId,
        float $cantidad,
        ?string $referenciaTipo = null,
        ?int $referenciaId = null,
        ?string $observaciones = null,
        ?int $usuarioId = null,
        ?string $unidad = null,
    ): MovimientoInventario {
        if ($cantidad <= 0) {
            throw new InventarioException('La cantidad de entrada debe ser mayor que cero.');
        }

        $producto = Producto::query()->find($productoId);
        if (! $producto) {
            throw new InventarioException('Producto no encontrado.', 404);
        }

        $cantidadBase = $this->unidadConversionService->resolverCantidadEnUnidadBase(
            $producto,
            $cantidad,
            $unidad,
        );

        return DB::transaction(function () use (
            $productoId,
            $cantidadBase,
            $referenciaTipo,
            $referenciaId,
            $observaciones,
            $usuarioId,
        ) {
            $inventario = Inventario::query()
                ->where('producto_id', $productoId)
                ->lockForUpdate()
                ->first();

            if (! $inventario) {
                throw new InventarioException('No existe inventario para este producto.', 404);
            }

            $stockAnterior = (float) $inventario->stock_fisico;
            $stockPosterior = $stockAnterior + $cantidadBase;

            $inventario->update(['stock_fisico' => $stockPosterior]);
            $inventario->refresh();
            $this->alertaInventarioService->verificarStockMinimo($inventario);

            return MovimientoInventario::query()->create([
                'producto_id' => $productoId,
                'tipo' => 'entrada',
                'cantidad' => $cantidadBase,
                'stock_anterior' => $stockAnterior,
                'stock_posterior' => $stockPosterior,
                'referencia_tipo' => $referenciaTipo,
                'referencia_id' => $referenciaId,
                'usuario_id' => $usuarioId,
                'observaciones' => $observaciones,
            ]);
        });
    }

    public function registrarAjuste(
        int $productoId,
        float $nuevoStock,
        string $observaciones,
        ?int $usuarioId = null,
    ): MovimientoInventario {
        if ($nuevoStock < 0) {
            throw new InventarioException('El nuevo stock no puede ser negativo.');
        }

        if (trim($observaciones) === '') {
            throw new InventarioException('La observación es obligatoria para registrar un ajuste.');
        }

        return DB::transaction(function () use ($productoId, $nuevoStock, $observaciones, $usuarioId) {
            $inventario = Inventario::query()
                ->where('producto_id', $productoId)
                ->lockForUpdate()
                ->first();

            if (! $inventario) {
                throw new InventarioException('No existe inventario para este producto.', 404);
            }

            $stockAnterior = (float) $inventario->stock_fisico;
            $stockPosterior = $nuevoStock;
            $cantidadMovimiento = abs($stockPosterior - $stockAnterior);

            if ($cantidadMovimiento === 0.0) {
                throw new InventarioException('El ajuste no modifica el stock actual.');
            }

            $inventario->update(['stock_fisico' => $stockPosterior]);
            $inventario->refresh();
            $this->alertaInventarioService->verificarStockMinimo($inventario);

            return MovimientoInventario::query()->create([
                'producto_id' => $productoId,
                'tipo' => 'ajuste',
                'cantidad' => $cantidadMovimiento,
                'stock_anterior' => $stockAnterior,
                'stock_posterior' => $stockPosterior,
                'referencia_tipo' => null,
                'referencia_id' => null,
                'usuario_id' => $usuarioId,
                'observaciones' => $observaciones,
            ]);
        });
    }

    public function comprometerStock(int $productoId, float $cantidadBase): Inventario
    {
        if ($cantidadBase <= 0) {
            throw new InventarioException('La cantidad a comprometer debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($productoId, $cantidadBase) {
            $inventario = Inventario::query()
                ->where('producto_id', $productoId)
                ->lockForUpdate()
                ->first();

            if (! $inventario) {
                throw new InventarioException('No existe inventario para este producto.', 404);
            }

            $stockDisponible = $inventario->stock_disponible;

            if ($stockDisponible < $cantidadBase) {
                throw new InventarioException(
                    'Stock insuficiente para comprometer la solicitud.',
                    422,
                    [
                        'stock_disponible' => $stockDisponible,
                        'cantidad_compromiso' => $cantidadBase,
                    ]
                );
            }

            $inventario->update([
                'stock_comprometido' => round((float) $inventario->stock_comprometido + $cantidadBase, 2),
            ]);

            return $inventario->refresh();
        });
    }

    public function liberarCompromiso(int $productoId, float $cantidadBase): Inventario
    {
        if ($cantidadBase <= 0) {
            throw new InventarioException('La cantidad a liberar debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($productoId, $cantidadBase) {
            $inventario = Inventario::query()
                ->where('producto_id', $productoId)
                ->lockForUpdate()
                ->first();

            if (! $inventario) {
                throw new InventarioException('No existe inventario para este producto.', 404);
            }

            $comprometidoActual = (float) $inventario->stock_comprometido;

            if ($comprometidoActual < $cantidadBase) {
                throw new InventarioException(
                    'No hay stock comprometido suficiente para liberar.',
                    422,
                    [
                        'stock_comprometido' => $comprometidoActual,
                        'cantidad_liberar' => $cantidadBase,
                    ]
                );
            }

            $inventario->update([
                'stock_comprometido' => round($comprometidoActual - $cantidadBase, 2),
            ]);

            return $inventario->refresh();
        });
    }

    /**
     * Crea una entrega operativa y descuenta inventario en una transacción atómica.
     * Solo debe invocarse desde el flujo de entregas operativas (fuente = sistema).
     *
     * @param  array{
     *     fecha: string,
     *     producto_id: int,
     *     area_id: int,
     *     cantidad: float|int|string,
     *     unidad: string,
     *     quien_recibe: string,
     *     entregado_por: string,
     *     quien_retira_cedula: string,
     *     quien_retira_nombre: string,
     *     registrado_por_user_id?: int|null,
     *     solicitud_id?: int|null
     * }  $datos
     * @return array{entrega: Entrega, movimiento: MovimientoInventario, solicitud?: \App\Models\Solicitud|null}
     */
    public function crearEntregaOperativa(array $datos, ?SolicitudService $solicitudService = null): array
    {
        $cantidadOriginal = (float) $datos['cantidad'];

        if ($cantidadOriginal <= 0) {
            throw new InventarioException('La cantidad de la entrega debe ser mayor que cero.');
        }

        $producto = Producto::query()->find($datos['producto_id']);
        if (! $producto) {
            throw new InventarioException('Producto no encontrado.', 404);
        }

        $personaService = app(PersonaService::class);
        $personaRetira = $personaService->resolverParaEntrega(
            $datos['quien_retira_cedula'],
            $datos['quien_retira_nombre'],
        );

        $cantidadBase = $this->unidadConversionService->resolverCantidadEnUnidadBase(
            $producto,
            $cantidadOriginal,
            $datos['unidad'],
        );

        $solicitudId = $datos['solicitud_id'] ?? null;
        $solicitudService ??= app(SolicitudService::class);

        return DB::transaction(function () use ($datos, $cantidadOriginal, $cantidadBase, $solicitudId, $solicitudService, $producto, $personaRetira) {
            $inventario = Inventario::query()
                ->where('producto_id', $datos['producto_id'])
                ->lockForUpdate()
                ->first();

            if (! $inventario) {
                throw new InventarioException(
                    'No existe inventario para realizar la entrega operativa.',
                    422
                );
            }

            $solicitud = null;

            if ($solicitudId !== null) {
                $solicitud = \App\Models\Solicitud::query()
                    ->with('detalles.producto')
                    ->lockForUpdate()
                    ->find($solicitudId);

                if (! $solicitud) {
                    throw new InventarioException('Solicitud vinculada no encontrada.', 404);
                }

                $solicitudService->validarEntregaVinculada($solicitud, (int) $datos['producto_id'], $cantidadBase);

                $comprometidoActual = (float) $inventario->stock_comprometido;
                if ($comprometidoActual < $cantidadBase) {
                    throw new InventarioException(
                        'Stock comprometido insuficiente para la entrega vinculada.',
                        422,
                        [
                            'stock_comprometido' => $comprometidoActual,
                            'cantidad_entregada' => $cantidadBase,
                        ]
                    );
                }

                $stockFisico = (float) $inventario->stock_fisico;
                if ($stockFisico < $cantidadBase) {
                    throw new InventarioException(
                        'Stock físico insuficiente para realizar la entrega.',
                        422,
                        [
                            'stock_fisico' => $stockFisico,
                            'cantidad_entregada' => $cantidadBase,
                        ]
                    );
                }

                $inventario->update([
                    'stock_comprometido' => round($comprometidoActual - $cantidadBase, 2),
                ]);
                $inventario->refresh();
            } else {
                $stockDisponible = $inventario->stock_disponible;

                if ($stockDisponible < $cantidadBase) {
                    throw new InventarioException(
                        'Stock insuficiente para realizar la entrega.',
                        422,
                        [
                            'stock_disponible' => $stockDisponible,
                            'cantidad_solicitada' => $cantidadOriginal,
                            'cantidad_convertida' => $cantidadBase,
                        ]
                    );
                }
            }

            $stockAnterior = (float) $inventario->stock_fisico;
            $stockPosterior = $stockAnterior - $cantidadBase;

            $entrega = Entrega::query()->create([
                'fecha' => $datos['fecha'],
                'producto_id' => $datos['producto_id'],
                'area_id' => $datos['area_id'],
                'cantidad' => $cantidadOriginal,
                'unidad' => $datos['unidad'],
                'quien_recibe' => $datos['quien_recibe'],
                'entregado_por' => $datos['entregado_por'],
                'quien_retira_cedula' => $personaRetira->cedula,
                'quien_retira_nombre' => $personaRetira->nombre_completo,
                'persona_retira_id' => $personaRetira->id,
                'registrado_por_user_id' => $datos['registrado_por_user_id'] ?? null,
                'fuente' => 'sistema',
                'solicitud_id' => $solicitudId,
            ]);

            $movimiento = MovimientoInventario::query()->create([
                'producto_id' => $datos['producto_id'],
                'tipo' => 'entrega',
                'cantidad' => $cantidadBase,
                'stock_anterior' => $stockAnterior,
                'stock_posterior' => $stockPosterior,
                'referencia_tipo' => 'Entrega',
                'referencia_id' => $entrega->id,
                'usuario_id' => $datos['registrado_por_user_id'] ?? null,
                'observaciones' => null,
            ]);

            $inventario->update(['stock_fisico' => $stockPosterior]);
            $inventario->refresh();
            $this->alertaInventarioService->verificarStockMinimo($inventario);

            if ($solicitud !== null) {
                $solicitud = $solicitudService->marcarEntregadaSiCompleta($solicitud);
            }

            return [
                'entrega' => $entrega,
                'movimiento' => $movimiento,
                'solicitud' => $solicitud,
            ];
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function obtenerPorProducto(int $productoId): ?array
    {
        $inventario = Inventario::query()
            ->with('producto')
            ->where('producto_id', $productoId)
            ->first();

        if (! $inventario) {
            return null;
        }

        return [
            'producto_id' => $inventario->producto_id,
            'producto' => $inventario->producto ? [
                'id' => $inventario->producto->id,
                'nombre' => $inventario->producto->nombre,
                'unidad_default' => $inventario->producto->unidad_default,
            ] : null,
            'stock_fisico' => (float) $inventario->stock_fisico,
            'stock_reserva' => (float) $inventario->stock_reserva,
            'stock_comprometido' => (float) $inventario->stock_comprometido,
            'stock_disponible' => $inventario->stock_disponible,
            'stock_minimo' => (float) $inventario->stock_minimo,
        ];
    }
}
