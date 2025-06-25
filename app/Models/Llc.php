<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Llc extends Model
{
    use HasFactory;
    protected $table = 'auditorias_llc';
    protected $guarded = [];

    public function operacion()
    {
        return $this->belongsTo(Operacion::class);
    }
}
