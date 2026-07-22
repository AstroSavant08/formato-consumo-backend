<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsumoPlanMesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'mes' => $this->mes,
            'cantidad' => $this->cantidad,
            'existencia' => $this->existencia,
            'dinero_solicitar' => $this->dinero_solicitar,
        ];
    }
}
