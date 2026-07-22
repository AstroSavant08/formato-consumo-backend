<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpsertFormatoPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'firma' => ['required', 'array'],
            'firma.fecha' => ['nullable', 'string', 'max:50'],
            'firma.solicitado_por' => ['nullable', 'string', 'max:255'],
            'firma.autorizado_por' => ['nullable', 'string', 'max:255'],
            'firma.cantidad_dinero' => ['nullable', 'string', 'max:255'],
            'pedido_especial' => ['nullable', 'array'],
            'pedido_especial.*.orden' => ['required', 'integer', 'min:1'],
            'pedido_especial.*.que' => ['required', 'string', 'max:500'],
            'pedido_especial.*.cantidad' => ['nullable', 'numeric', 'min:0'],
            'productos' => ['required', 'array', 'min:1'],
            'productos.*.producto_id' => ['nullable', 'integer', 'exists:productos,id'],
            'productos.*.nombre' => ['required', 'string', 'max:255'],
            'productos.*.stock_debido' => ['required', 'numeric', 'min:0'],
            'productos.*.orden' => ['required', 'integer', 'min:1'],
            'productos.*.dinero_solicitado' => ['nullable', 'numeric', 'min:0'],
            'productos.*.meses' => ['required', 'array', 'min:1', 'max:12'],
            'productos.*.meses.*.mes' => ['required', 'integer', 'min:0', 'max:11'],
            'productos.*.meses.*.cantidad' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $routeAnio = (int) $this->route('anio');
            $bodyAnio = (int) $this->input('anio');

            if ($routeAnio !== $bodyAnio) {
                $validator->errors()->add('anio', 'El año del cuerpo debe coincidir con el año de la ruta.');
            }

            foreach ($this->input('productos', []) as $productoIndex => $producto) {
                $meses = collect($producto['meses'] ?? [])->pluck('mes');

                if ($meses->duplicates()->isNotEmpty()) {
                    $validator->errors()->add(
                        "productos.{$productoIndex}.meses",
                        'No puede haber meses duplicados dentro de la misma línea.'
                    );
                }
            }
        });
    }
}
