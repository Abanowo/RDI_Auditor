<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de conceptos de un producto. tipo_operacion y sucursal en null = aplica a todas.
 */
class PlantillaProducto extends Model
{
    protected $table = 'plantillas_productos';

    protected $fillable = ['producto', 'tipo_operacion', 'sucursal', 'descripcion', 'activo', 'user_id'];

    protected $casts = ['activo' => 'boolean'];

    public function conceptos()
    {
        return $this->hasMany(PlantillaConcepto::class, 'plantilla_id')->orderBy('orden');
    }

    /**
     * La plantilla que mejor corresponde a un producto, tipo de operación y sucursal.
     * Prioridad: coincide en todo → misma sucursal (ambas operaciones) → mismo tipo (todas
     * las sucursales) → general del producto.
     */
    public static function mejorPara($producto, $tipo = null, $sucursal = null)
    {
        $candidatas = static::where('activo', true)
            ->whereRaw('LOWER(TRIM(producto)) = ?', [mb_strtolower(trim((string) $producto), 'UTF-8')])
            ->get();

        return $candidatas->sortByDesc(function ($p) use ($tipo, $sucursal) {
            if ($p->tipo_operacion && $p->tipo_operacion !== $tipo) return -1;
            if ($p->sucursal && $p->sucursal !== $sucursal) return -1;

            return ($p->sucursal ? 2 : 0) + ($p->tipo_operacion ? 1 : 0);
        })->first(function ($p) use ($tipo, $sucursal) {
            return (!$p->tipo_operacion || $p->tipo_operacion === $tipo)
                && (!$p->sucursal || $p->sucursal === $sucursal);
        });
    }
}
