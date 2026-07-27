<?php

namespace App\Services;

use App\Exceptions\StagingHomologacionException;
use App\Models\Entrega;
use App\Models\ExcelImportHomologacion;
use App\Models\ExcelImportStaging;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;

class StagingHomologacionService
{
    public function homologar(
        int $stagingId,
        int $productoIdDestino,
        ?string $notas,
        ?string $actor = null,
        bool $confirmarReemplazo = false,
    ): ExcelImportHomologacion {
        return DB::transaction(function () use ($stagingId, $productoIdDestino, $notas, $actor, $confirmarReemplazo) {
            $staging = ExcelImportStaging::query()->find($stagingId);

            if (! $staging) {
                throw new StagingHomologacionException('Registro de staging no encontrado.', 404);
            }

            $this->assertStagingCanBeHomologated($staging);

            if (Entrega::query()->where('staging_id', $staging->id)->exists()) {
                throw new StagingHomologacionException(
                    'No se puede homologar: ya existe una entrega asociada a este registro de staging.',
                    422
                );
            }

            $existingHomologacion = ExcelImportHomologacion::query()
                ->where('staging_id', $staging->id)
                ->first();

            if ($existingHomologacion && ! $confirmarReemplazo) {
                throw new StagingHomologacionException(
                    'Ya existe una homologación manual para este registro; se requiere confirmación explícita para reemplazarla.',
                    422
                );
            }

            $producto = Producto::query()->find($productoIdDestino);

            if (! $producto) {
                throw new StagingHomologacionException('Producto destino no encontrado.', 404);
            }

            $this->assertProductoDestinoValido($producto);

            $homologacion = ExcelImportHomologacion::query()->updateOrCreate(
                ['staging_id' => $staging->id],
                [
                    'producto_id_destino' => $producto->id,
                    'confirmado_por' => $actor,
                    'fecha_confirmacion' => now(),
                    'notas' => $notas,
                ]
            );

            return $homologacion->fresh(['staging', 'productoDestino']);
        });
    }

    /**
     * @param  array<int>  $stagingIds
     * @return array{
     *     homologados: array<int, array<string, mixed>>,
     *     omitidos: array<int, array<string, mixed>>,
     *     errores: array<int, array<string, mixed>>
     * }
     */
    public function homologarBulk(
        array $stagingIds,
        int $productoIdDestino,
        ?string $notas,
        ?string $actor = null,
        bool $confirmarReemplazo = false,
    ): array {
        $producto = Producto::query()->find($productoIdDestino);

        if (! $producto) {
            throw new StagingHomologacionException('Producto destino no encontrado.', 404);
        }

        $this->assertProductoDestinoValido($producto);

        return DB::transaction(function () use ($stagingIds, $producto, $notas, $actor, $confirmarReemplazo) {
            $report = [
                'homologados' => [],
                'omitidos' => [],
                'errores' => [],
            ];

            $stagingRecords = ExcelImportStaging::query()
                ->with('homologacion')
                ->whereIn('id', $stagingIds)
                ->get()
                ->keyBy('id');

            foreach ($stagingIds as $stagingId) {
                $staging = $stagingRecords->get($stagingId);

                if (! $staging) {
                    $report['errores'][] = [
                        'staging_id' => $stagingId,
                        'motivo' => 'Registro de staging no encontrado.',
                    ];
                    continue;
                }

                if ($staging->estado === 'importado') {
                    $report['omitidos'][] = $this->buildOmitidoEntry(
                        $staging,
                        'No se puede homologar un registro ya importado a entregas.'
                    );
                    continue;
                }

                if ($staging->estado === 'rechazado') {
                    $report['omitidos'][] = $this->buildOmitidoEntry(
                        $staging,
                        'No se puede homologar un registro rechazado.'
                    );
                    continue;
                }

                if (Entrega::query()->where('staging_id', $staging->id)->exists()) {
                    $report['omitidos'][] = $this->buildOmitidoEntry(
                        $staging,
                        'Ya existe una entrega asociada a este registro de staging.'
                    );
                    continue;
                }

                if ($staging->homologacion && ! $confirmarReemplazo) {
                    $report['omitidos'][] = $this->buildOmitidoEntry(
                        $staging,
                        'Ya tiene homologación manual; requiere confirmación explícita de reemplazo.'
                    );
                    continue;
                }

                $homologacion = ExcelImportHomologacion::query()->updateOrCreate(
                    ['staging_id' => $staging->id],
                    [
                        'producto_id_destino' => $producto->id,
                        'confirmado_por' => $actor,
                        'fecha_confirmacion' => now(),
                        'notas' => $notas,
                    ]
                );

                $homologacion->load('productoDestino');

                $report['homologados'][] = [
                    'staging_id' => $staging->id,
                    'fila_excel' => $staging->fila_excel,
                    'producto_raw' => $staging->producto_raw,
                    'homologacion' => [
                        'id' => $homologacion->id,
                        'staging_id' => $homologacion->staging_id,
                        'producto_id_destino' => $homologacion->producto_id_destino,
                        'producto_destino' => [
                            'id' => $homologacion->productoDestino->id,
                            'nombre' => $homologacion->productoDestino->nombre,
                        ],
                        'confirmado_por' => $homologacion->confirmado_por,
                        'fecha_confirmacion' => $homologacion->fecha_confirmacion?->toIso8601String(),
                        'notas' => $homologacion->notas,
                    ],
                ];
            }

            return $report;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOmitidoEntry(ExcelImportStaging $staging, string $motivo): array
    {
        return [
            'staging_id' => $staging->id,
            'fila_excel' => $staging->fila_excel,
            'producto_raw' => $staging->producto_raw,
            'motivo' => $motivo,
        ];
    }

    public function findForStaging(int $stagingId): ?ExcelImportHomologacion
    {
        return ExcelImportHomologacion::query()
            ->with(['productoDestino'])
            ->where('staging_id', $stagingId)
            ->first();
    }

    private function assertStagingCanBeHomologated(ExcelImportStaging $staging): void
    {
        if ($staging->estado === 'importado') {
            throw new StagingHomologacionException(
                'No se puede homologar un registro ya importado a entregas.',
                422
            );
        }

        if ($staging->estado === 'rechazado') {
            throw new StagingHomologacionException(
                'No se puede homologar un registro rechazado.',
                422
            );
        }
    }

    private function assertProductoDestinoValido(Producto $producto): void
    {
        if (! $producto->activo) {
            throw new StagingHomologacionException('El producto destino está inactivo.', 422);
        }

        if ($producto->es_historico_excel) {
            throw new StagingHomologacionException(
                'El producto destino no puede ser un producto histórico de Excel.',
                422
            );
        }
    }
}
