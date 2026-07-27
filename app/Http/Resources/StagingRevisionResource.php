<?php

namespace App\Http\Resources;

use App\Support\ExcelDatePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StagingRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fila_excel' => $this->fila_excel,
            'fecha_raw' => $this->fecha_raw,
            'fecha_presentacion' => ExcelDatePresenter::present($this->fecha_raw),
            'producto_raw' => $this->producto_raw,
            'cantidad_raw' => $this->cantidad_raw,
            'unidad_raw' => $this->unidad_raw,
            'area_raw' => $this->area_raw,
            'quien_recibe_raw' => $this->quien_recibe_raw,
            'entrega_raw' => $this->entrega_raw,
            'estado' => $this->estado,
            'errores_json' => $this->errores_json,
            'es_posible_duplicado' => $this->es_posible_duplicado,
            'producto_resuelto' => $this->whenLoaded('producto', fn () => $this->producto ? [
                'id' => $this->producto->id,
                'nombre' => $this->producto->nombre,
                'es_historico_excel' => $this->producto->es_historico_excel,
            ] : null),
            'area_resuelta' => $this->whenLoaded('area', fn () => $this->area ? [
                'id' => $this->area->id,
                'codigo' => $this->area->codigo,
                'nombre' => $this->area->nombre,
            ] : null),
            'tiene_homologacion' => $this->relationLoaded('homologacion')
                ? $this->homologacion !== null
                : null,
            'homologacion' => $this->whenLoaded('homologacion', fn () => $this->homologacion
                ? new ExcelImportHomologacionResource($this->homologacion)
                : null),
        ];
    }
}
