<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empresas extends Model
{
    use HasFactory;
    public function importaciones()
    {
        return $this->hasMany(Importacion::class, 'id_cliente', 'id');
    }

    public function exportaciones()
    {
        return $this->hasMany(Exportacion::class, 'id_cliente', 'id');
    }
}
