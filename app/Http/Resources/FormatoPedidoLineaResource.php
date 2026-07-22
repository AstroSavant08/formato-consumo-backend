<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormatoPedidoLineaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meses = collect(range(0, 11))->map(function (int $mes) {
            $registro = $this->meses->firstWhere('mes', $mes);

            return [
                'mes' => $mes,
                'cantidad' => $registro?->cantidad ?? 0,
                'existencia' => $registro?->existencia ?? 0,
                'dinero_solicitar' => $registro?->dinero_solicitar,
            ];
        });

        return [
            'id' => $this->id,
            'producto_id' => $this->producto_id,
            'nombre' => $this->nombre_producto,
            'stock_debido' => $this->stock_debido,
            'orden' => $this->orden,
            'dinero_solicitado' => $this->dinero_solicitado,
            'meses' => $meses,
        ];
    }
}
