<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntregaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha?->format('Y-m-d'),
            'area' => new AreaResource($this->whenLoaded('area')),
            'producto' => new ProductoResource($this->whenLoaded('producto')),
            'cantidad' => $this->cantidad,
            'unidad' => $this->unidad,
            'quien_recibe' => $this->quien_recibe,
            'entregado_por' => $this->entregado_por,
            'quien_retira_cedula' => $this->quien_retira_cedula,
            'quien_retira_nombre' => $this->quien_retira_nombre,
            'persona_retira_id' => $this->persona_retira_id,
            'registrado_por_user_id' => $this->registrado_por_user_id,
            'fuente' => $this->fuente,
            'solicitud_id' => $this->solicitud_id,
            'excel_fila' => $this->excel_fila,
            'es_posible_duplicado' => $this->es_posible_duplicado,
        ];
    }
}
