<?php
use App\Http\Controllers\AuditoriaImpuestosController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IngresoConciliadoController;
use App\Http\Controllers\ControlProveedoresController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Rutas de ImportController
Route::post('/importar-estado-de-cuenta', [AuditoriaImpuestosController::class, 'procesarEstadoDeCuenta'])->name('import.process');
Route::post('/auditoria/ejecutar-comando', [AuditoriaImpuestosController::class, 'ejecutarComandoDeTareaEnCola']);

// Rutas de DocumentoController
Route::get('/documentos/ver', [AuditoriaImpuestosController::class, 'mostrarPdf'])->name('documentos.ver');
Route::get('/documentos/ver/{tipo}/{id}', [AuditoriaImpuestosController::class, 'mostrarDocumentoLocal'])->name('documentos.local.mostrar');
Route::get('/documentos/proxy', [AuditoriaImpuestosController::class, 'proxyDocumentoExterno'])->name('documentos.externo.proxy');
Route::get('/documentos/reporte-auditoria/{tarea}/{tipo}', [AuditoriaImpuestosController::class, 'descargarReporteAuditoria'])->name('reportes.auditoria.descargar');

// Rutas de AuditoriaImpuestosController
Route::get('/auditoria/conteo-sc-diario', [AuditoriaImpuestosController::class, 'getConteoScDiario']);
Route::get('/auditoria/conteo-auditoria-diaria', [AuditoriaImpuestosController::class, 'getConteoAuditoriaDiario']);
Route::get('/auditoria/tareas-completadas', [AuditoriaImpuestosController::class, 'getTareasCompletadas']);
Route::get('/auditoria/sucursales', [AuditoriaImpuestosController::class, 'getSucursales']);
Route::get('/auditoria/clientes', [AuditoriaImpuestosController::class, 'getClientes']);
Route::get('/auditoria/exportar', [AuditoriaImpuestosController::class, 'exportarFacturado'])->name('auditoria.exportar');
Route::get('/auditoria', [AuditoriaImpuestosController::class, 'index'])->name('auditoria.index');

// ====================================================================
// RUTAS PARA EL MÓDULO DE CONTROL DE PROVEEDORES (Consumidas por Vue)
// ====================================================================
Route::prefix('control-proveedores')->group(function () {
    Route::get('/', [ControlProveedoresController::class, 'index']);
    Route::put('/{id}', [ControlProveedoresController::class, 'update']);
    Route::post('/{id}/enviar-orden', [ControlProveedoresController::class, 'enviarAOrdenPago']);
});

// =====================================================================
// RUTAS PARA EL MÓDULO DE FINANZAS E INGRESOS
// =====================================================================

// 🔥 1. RUTAS ESPECÍFICAS (SIN {id}) - SIEMPRE VAN ARRIBA
Route::post('/ingresos-conciliados/generar-complemento', [IngresoConciliadoController::class, 'generarComplemento']);
Route::post('/ingresos-conciliados/timbrar-complemento', [IngresoConciliadoController::class, 'timbrarComplemento']);
Route::get('/ingresos-conciliados/{id}/complemento/pdf', [IngresoConciliadoController::class, 'verComplementoPdf']);
Route::post('/ingresos-conciliados/{id}/complemento/enviar-correo', [IngresoConciliadoController::class, 'enviarCorreoComplemento']);

Route::get('/ingresos-conciliados/opciones', [IngresoConciliadoController::class, 'opciones']);
Route::get('/ingresos-conciliados/listar-pedimentos', [IngresoConciliadoController::class, 'listarPedimentosSheet']); // ¡Agregada!
Route::post('/ingresos-conciliados/buscar-sheet', [IngresoConciliadoController::class, 'buscarEnSheet']); // ¡Movida hacia arriba!

Route::get('/ingresos-conciliados', [IngresoConciliadoController::class, 'index']);
Route::post('/ingresos-conciliados', [IngresoConciliadoController::class, 'store']);

// 🔥 2. RUTAS DINÁMICAS (CON {id}) - SIEMPRE VAN DEBAJO
Route::put('/ingresos-conciliados/{id}', [IngresoConciliadoController::class, 'update']);
Route::delete('/ingresos-conciliados/{id}', [IngresoConciliadoController::class, 'destroy']);

// RUTAS DE SALDOS A FAVOR
Route::get('/saldos-favor', [IngresoConciliadoController::class, 'indexSaldos']);
Route::post('/saldos-favor', [IngresoConciliadoController::class, 'storeSaldo']);
Route::put('/saldos-favor/{id}/aplicar', [IngresoConciliadoController::class, 'marcarAplicadoSaldo']);
Route::post('/saldos-favor/{id}/notificar', [IngresoConciliadoController::class, 'notificarCliente']);
Route::put('/saldos-favor/{id}/reactivar', [IngresoConciliadoController::class, 'reactivarSaldo']);
Route::put('/saldos-favor/{id}', [IngresoConciliadoController::class, 'updateSaldo']);
Route::delete('/saldos-favor/{id}', [IngresoConciliadoController::class, 'destroySaldo']);


// Atrapa cualquier URL que no coincida con las de arriba y le entrega el control a Vue Router.
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');