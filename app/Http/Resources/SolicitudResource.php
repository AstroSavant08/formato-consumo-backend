<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitudResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'area_id' => $this->area_id,
            'area' => new AreaResource($this->whenLoaded('area')),
            'usuario_id' => $this->usuario_id,
            'usuario' => $this->whenLoaded('usuario', fn () => [
                'id' => $this->usuario->id,
                'name' => $this->usuario->name,
                'email' => $this->usuario->email,
            ]),
            'fecha' => $this->fecha?->format('Y-m-d'),
            'estado' => $this->estado,
            'justificacion' => $this->justificacion,
            'observaciones' => $this->observaciones,
            'total' => (float) $this->total,
            'aprobado_por' => $this->aprobado_por,
            'aprobado_por_usuario' => $this->whenLoaded('aprobadoPor', fn () => [
                'id' => $this->aprobadoPor->id,
                'name' => $this->aprobadoPor->name,
                'email' => $this->aprobadoPor->email,
            ]),
            'aprobado_at' => $this->aprobado_at?->toIso8601String(),
            'detalles' => SolicitudDetalleResource::collection($this->whenLoaded('detalles')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
