<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormatoPedidoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'anio' => $this->anio,
            'tipo' => $this->tipo,
            'firma' => [
                'fecha' => $this->fecha_pedido,
                'solicitado_por' => $this->solicitado_por,
                'autorizado_por' => $this->autorizado_por,
                'cantidad_dinero' => $this->cantidad_dinero_solicitada,
            ],
            'pedido_especial' => $this->whenLoaded('pedidosEspeciales', function () {
                return $this->pedidosEspeciales->map(fn ($item) => [
                    'id' => $item->id,
                    'orden' => $item->orden,
                    'que' => $item->descripcion,
                    'cantidad' => $item->cantidad,
                ])->values();
            }),
            'productos' => FormatoPedidoLineaResource::collection($this->whenLoaded('lineas')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
