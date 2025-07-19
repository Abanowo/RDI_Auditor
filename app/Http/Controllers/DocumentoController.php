<?php

namespace App\Http\Controllers;
use App\Models\Operacion;
use App\Models\Auditoria;
use App\Models\AuditoriaTotalSC;
use App\Models\AuditoriaTareas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DocumentoController extends Controller
{
    /**
     * Muestra un documento local (ej. impuestos) desde el storage.
     */
    public function mostrarDocumentoLocal(Request $request, string $tipo, int $id)
    {
        $factura = Auditoria::findOrFail($id);
        $rutaAbsoluta = $factura->ruta_pdf;

        // Verificamos que la ruta exista y que el archivo sea legible.
        if (!$rutaAbsoluta || !is_file($rutaAbsoluta) || !is_readable($rutaAbsoluta)) {
             abort(404, 'El archivo local no fue encontrado o no se puede leer.');
        }

        // ✅ PASO 1: Detectamos la extensión del archivo dinámicamente.
        $extension = strtolower(pathinfo($rutaAbsoluta, PATHINFO_EXTENSION));

        // PASO 2: Si la petición solo quiere información, devolvemos la extensión.
        if ($request->has('info')) {
            return response()->json(['tipo_archivo' => $extension]);
        }

        // PASO 3: Si no es una petición de info, servimos el archivo con el Content-Type correcto.
        $contenido = file_get_contents($rutaAbsoluta);
        $nombreArchivo = basename($rutaAbsoluta);

        // Mapeo de extensiones a tipos MIME para las cabeceras HTTP.
        $mimeTypes = [
            'pdf'  => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls'  => 'application/vnd.ms-excel',
            // Puedes añadir más tipos de archivo aquí si es necesario
        ];

        // Usamos el tipo MIME correspondiente a la extensión, o un tipo genérico si no se encuentra.
        $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';

        return response($contenido, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="' . $nombreArchivo . '"',
        ]);
    }

    /**
     * Actúa como un proxy para obtener y servir un documento desde una URL externa.
     * Esto resuelve los problemas de CORS.
     */
    public function proxyDocumentoExterno(Request $request)
    {
        // Validamos que nos hayan enviado una URL.
        $request->validate(['url' => 'required|url']);
        $urlExterna = $request->input('url');

        if ($request->has('info')) {
            // Como tu regla de negocio dice que todas las facturas externas son PDF,
            // podemos responder directamente sin necesidad de descargar el archivo.
            return response()->json(['tipo_archivo' => 'pdf']);
        }
        try {
            // Hacemos la petición desde nuestro servidor a la URL externa.
            $response = Http::withoutVerifying()->get($urlExterna);

            if ($response->failed()) {
                abort(502, 'No se pudo obtener el documento desde el servidor de origen.');
            }

            // Servimos el contenido del PDF al navegador con las cabeceras correctas.
            return response($response->body(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . basename($urlExterna) . '"',
            ]);

        } catch (\Exception $e) {
            abort(500, 'Ocurrió un error al procesar el documento externo.');
        }
    }

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
