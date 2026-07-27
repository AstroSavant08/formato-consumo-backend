<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array<string, mixed> */
class SemaforoConsumoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'producto_id' => $this->resource['producto_id'],
            'mes' => $this->resource['mes'],
            'anio' => $this->resource['anio'],
            'area_id' => $this->resource['area_id'],
            'consumo_actual' => $this->resource['consumo_actual'],
            'promedio_historico' => $this->resource['promedio_historico'],
            'variacion_porcentual' => $this->resource['variacion_porcentual'],
            'severidad' => $this->resource['severidad'],
            'unidad_base' => $this->resource['unidad_base'],
            'anios_historico_considerados' => $this->resource['anios_historico_considerados'],
            'totales_por_anio' => $this->resource['totales_por_anio'],
            'entregas_omitidas' => $this->resource['entregas_omitidas'],
            'mensaje' => $this->resource['mensaje'],
            'configuracion' => $this->resource['configuracion'],
        ];
    }
}
