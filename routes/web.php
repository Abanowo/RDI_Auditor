<?php
use App\Http\Controllers\ImportController;
use App\Http\Controllers\AuditoriaImpuestosController;
use App\Http\Controllers\DocumentoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
// Esta única ruta manejará tanto la carga inicial de la página como las peticiones de datos.

//Rutas de ImportController
Route::post('/importar-estado-de-cuenta', [AuditoriaImpuestosController::class, 'procesarEstadoDeCuenta'])->name('import.process');

//ANTHONY, AQUI CAMBIA LA RUTA PARA QUE SE ADAPTE TU PROYECTO
Route::post('/auditoria/ejecutar-comando', [AuditoriaImpuestosController::class, 'ejecutarComandoDeTareaEnCola']);

//Rutas de DocumentoController
Route::get('/documentos/ver', [AuditoriaImpuestosController::class, 'mostrarPdf'])->name('documentos.ver');
// La ruta ahora espera un ID de tarea y una cadena de texto ('facturado' o 'pendiente').
// Ruta para servir archivos locales (impuestos) por su tipo e ID
Route::get('/documentos/ver/{tipo}/{id}', [AuditoriaImpuestosController::class, 'mostrarDocumentoLocal'])->name('documentos.local.mostrar');
// Nueva ruta para servir como proxy para URLs externas
Route::get('/documentos/proxy', [AuditoriaImpuestosController::class, 'proxyDocumentoExterno'])->name('documentos.externo.proxy');
// La ruta ahora espera un ID de tarea y una cadena de texto ('facturado' o 'pendiente').
Route::get('/documentos/reporte-auditoria/{tarea}/{tipo}', [AuditoriaImpuestosController::class, 'descargarReporteAuditoria'])->name('reportes.auditoria.descargar');

//Rutas de AuditoriaImpuestosController
// Rutas API para los filtros del frontend
Route::get('/auditoria/conteo-sc-diario', [AuditoriaImpuestosController::class, 'getConteoScDiario']);
Route::get('/auditoria/conteo-auditoria-diaria', [AuditoriaImpuestosController::class, 'getConteoAuditoriaDiario']);
Route::get('/auditoria/tareas-completadas', [AuditoriaImpuestosController::class, 'getTareasCompletadas']);
Route::get('/auditoria/sucursales', [AuditoriaImpuestosController::class, 'getSucursales']);
Route::get('/auditoria/clientes', [AuditoriaImpuestosController::class, 'getClientes']);
Route::get('/auditoria/exportar', [AuditoriaImpuestosController::class, 'exportarFacturado'])->name('auditoria.exportar');
Route::get('/auditoria', [AuditoriaImpuestosController::class, 'index'])->name('auditoria.index');
Route::get('/', function () {
    return view('welcome');
});
