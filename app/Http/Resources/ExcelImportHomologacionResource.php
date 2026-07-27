<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExcelImportHomologacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staging_id' => $this->staging_id,
            'producto_id_destino' => $this->producto_id_destino,
            'producto_destino' => $this->whenLoaded('productoDestino', fn () => [
                'id' => $this->productoDestino->id,
                'nombre' => $this->productoDestino->nombre,
            ]),
            'confirmado_por' => $this->confirmado_por,
            'fecha_confirmacion' => $this->fecha_confirmacion?->toIso8601String(),
            'notas' => $this->notas,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
