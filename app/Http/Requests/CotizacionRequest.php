<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CotizacionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'empresa_id'     => 'nullable|integer',
            'cliente'        => 'required|string|max:255',
            'contacto'       => 'nullable|string|max:255',
            'fecha'          => 'required|date',
            'valida_hasta'   => 'nullable|date|after_or_equal:fecha',
            'puerto'         => 'nullable|string|max:100',
            'tipo_operacion' => 'required|in:IMPORTACION,EXPORTACION',
            'producto'       => 'nullable|string|max:255',
            'vendedor'       => 'nullable|string|max:255',
            // No se captura en el modal: se toma de config('cotizador.tipo_cambio')
            'tipo_cambio'    => 'nullable|numeric|min:0.0001',
            'aprobada'       => 'nullable|boolean',

            // conceptos[seccion_id][n][campo]
            'conceptos'                   => 'nullable|array',
            'conceptos.*'                 => 'array',
            'conceptos.*.*.codigo'        => 'nullable|string|max:20',
            'conceptos.*.*.descripcion'   => 'required|string|max:255',
            'conceptos.*.*.cantidad'      => 'required|numeric|min:0',
            'conceptos.*.*.precio_usd'    => 'nullable|numeric|min:0',
            'conceptos.*.*.precio_mxn'    => 'nullable|numeric|min:0',
            'conceptos.*.*.descripcion_doc' => ['required', 'string', Rule::in(config('cotizador.descripciones_doc'))],

            'terminos'         => 'nullable|array',
            'terminos.*.texto' => 'nullable|string|max:3000',
        ];
    }

    public function attributes()
    {
        return [
            'cliente'                     => 'prospecto / cliente',
            'fecha'                       => 'fecha de cotización',
            'valida_hasta'                => 'válida hasta',
            'tipo_operacion'              => 'tipo de operación',
            'tipo_cambio'                 => 'tipo de cambio',
            'conceptos.*.*.descripcion'   => 'descripción del concepto',
            'conceptos.*.*.cantidad'      => 'cantidad',
            'conceptos.*.*.precio_usd'    => 'dólares',
            'conceptos.*.*.precio_mxn'    => 'pesos',
            'conceptos.*.*.descripcion_doc' => 'descripción doc',
        ];
    }
}