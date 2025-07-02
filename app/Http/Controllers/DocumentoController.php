<?php

namespace App\Http\Controllers;
use App\Models\Operacion;
use App\Models\Auditoria;
use App\Models\AuditoriaTotalSC;
use Illuminate\Http\Request;

class DocumentoController extends Controller
{
    public function mostrarPdf(Request $request)
    {
        // 1. Validamos que nos lleguen los parámetros 'tipo' e 'id'
        $request->validate([
            'tipo' => 'required|string|in:auditoria,sc', // Solo aceptamos estos dos tipos
            'id'   => 'required|integer',
        ]);

        $tipo = $request->input('tipo');
        $id = $request->input('id');
        $record = null;

        // 2. Buscamos el registro en la tabla correcta según el 'tipo'
        if ($tipo === 'sc') {
            $record = AuditoriaTotalSC::find($id);
        } else {
            // Para cualquier otro caso ('impuestos', 'flete', etc.) buscamos en 'auditorias'
            $record = Auditoria::find($id);
        }

        // Si no se encontró el registro en la BD, devolvemos error
        if (!$record) {
            abort(404, 'Registro no encontrado en la base de datos.');
        }

        // 3. Obtenemos la ruta absoluta del PDF desde el registro
        $rutaCompleta = $record->ruta_pdf;

        // 4. Verificamos si el archivo existe en esa ruta (¡requiere permisos de red!)
        if (!$rutaCompleta || !file_exists($rutaCompleta)) {
            abort(404, 'Documento no encontrado en la ubicación física.');
        }

        // 5. Si todo está bien, Laravel sirve el archivo al navegador
        return response()->file($rutaCompleta);
    }
}
