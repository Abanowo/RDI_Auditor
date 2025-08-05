<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use App\Models\Operacion;
use App\Models\Auditoria;
use App\Models\AuditoriaTareas;
use App\Models\AuditoriaTotalSC;
use Illuminate\Support\Facades\Log; // Para registrar errores

class ImportController extends Controller
{
    public function procesarEstadoDeCuenta(Request $request)
    {
        $datosValidados = $request->validate(
            [
            'estado_de_cuenta' => 'required|file', // Aceptamos cualquier archivo
            'banco' => 'required|string',
            'sucursal' => 'required|string',
            'archivos_extras.*' => 'nullable|file', // Para los archivos extra (opcional)
            ]);

        // 1. Guardamos el estado de cuenta principal
        $rutaPrincipal = $request->file('estado_de_cuenta')->store('operaciones/estados_de_cuenta');

        // 2. Guardamos los archivos extra si existen
        $rutasExtras = [];
        if ($request->hasFile('archivos_extras')) {
            foreach ($request->file('archivos_extras') as $file) {
                $rutasExtras[] = $file->store('importaciones/extras');
            }
        }

        $nombreArchivo = $datosValidados['estado_de_cuenta']->getClientOriginalName();
        // 3. Creamos el registro de la nueva tarea en la base de datos
        AuditoriaTareas::create(
            [
            'banco'                 => $datosValidados['banco'],
            'sucursal'              => $datosValidados['sucursal'],
            'periodo_meses'         => '4',
            'nombre_archivo'        => $nombreArchivo,
            'ruta_estado_de_cuenta' => $rutaPrincipal,
            'rutas_extras'          => json_encode($rutasExtras),
            'status'                => 'pendiente', // La tarea empieza como pendiente
            ]);

        // 4. Devolvemos una respuesta inmediata y exitosa al usuario
        return response()->json(
            [
            'message' => '¡Solicitud recibida! El procesamiento ha comenzado y se te notificará al completarse.'
            ], 202); // 202 Accepted
    }

}
