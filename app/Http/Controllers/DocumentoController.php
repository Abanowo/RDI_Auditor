<?php

namespace App\Http\Controllers;
use App\Models\Operacion;
use App\Models\Auditoria;
use App\Models\AuditoriaTotalSC;
use App\Models\AuditoriaTareas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentoController extends Controller
{
    public function mostrarPdf(Request $request)
    {
        // 1. Validamos que nos lleguen los parámetros 'tipo' e 'id'
        $request->validate(
            [
                'tipo' => 'required|string',
                'id' => 'required|integer', // <-- Cambiamos 'id' por 'uuid' y validamos que sea un UUID
            ]);

        $tipo = $request->input('tipo');
        $uuid = $request->input('id'); // <-- Usamos el uuid
        $record = null;

        // 2. Buscamos el registro por UUID en la tabla correcta
        if ($tipo === 'sc') {
            $record = AuditoriaTotalSC::where('id', $uuid)->first(); // <-- Buscamos por 'uuid'
        } else {
            $record = Auditoria::where('id', $uuid)->first(); // <-- Buscamos por 'uuid'
        }

        // Si no se encontró el registro en la BD, devolvemos error
        if (!$record) { abort(404, 'Registro no encontrado en la base de datos.'); }

        // 3. Obtenemos la ruta absoluta del PDF desde el registro
        $rutaCompleta = $record->ruta_pdf;

        // 4. Verificamos si el archivo existe en esa ruta (¡requiere permisos de red!)
        if (!$rutaCompleta || !file_exists($rutaCompleta)) { abort(404, 'Documento no encontrado en la ubicación física.'); }

        // 5. Si todo está bien, Laravel sirve el archivo al navegador
        return response()->file($rutaCompleta);
    }

     /**
     * Inicia la descarga de un reporte de auditoría asociado a una tarea.
     *
     * @param  \App\Models\AuditoriaTareas $tarea La tarea obtenida automáticamente por Laravel.
     * @param  string $tipo El tipo de reporte ('facturado' o 'pendiente') que viene de la URL.
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\Response
     */
    public function descargarReporteAuditoria(AuditoriaTareas $tarea, string $tipo)
    {
        // 1. Inicializamos las variables que contendrán la ruta y el nombre del archivo.
        $rutaGuardada = null;
        $nombreDescarga = null;

        // 2. Decidimos qué archivo servir basándonos en el parámetro $tipo de la URL.
        if ($tipo === 'facturado') {
            $rutaGuardada = $tarea->ruta_reporte_impuestos;
            $nombreDescarga = $tarea->nombre_reporte_impuestos;
        } elseif ($tipo === 'pendiente') {
            $rutaGuardada = $tarea->ruta_reporte_impuestos_pendientes;
            $nombreDescarga = $tarea->nombre_reporte_pendientes;
        }

        // 3. Verificamos que el archivo realmente exista en nuestro disco 'public'.
        if (!$rutaGuardada || !Storage::disk('public')->exists($rutaGuardada)) {
            // Si no existe, devolvemos un error 404 (No Encontrado).
            abort(404, 'El archivo solicitado no existe o ha sido eliminado.');
        }

        // 4. Usamos el método download() de Storage para iniciar la descarga.
        return Storage::disk('public')->download($rutaGuardada, $nombreDescarga);
    }
}
