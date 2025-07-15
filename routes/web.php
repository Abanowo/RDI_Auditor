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
Route::post('/importar-estado-de-cuenta', [ImportController::class, 'procesarEstadoDeCuenta'])->name('import.process');

//Rutas de DocumentoController
// La ruta no necesita cambiar si usamos parámetros GET
Route::get('/documentos/ver', [DocumentoController::class, 'mostrarPdf'])->name('documentos.ver');

//Rutas de AuditoriaImpuestosController
// Rutas API para los filtros del frontend
Route::get('/auditoria/sucursales', [AuditoriaImpuestosController::class, 'getSucursales']);
Route::get('/auditoria/clientes', [AuditoriaImpuestosController::class, 'getClientes']);
Route::get('/auditoria/exportar', [AuditoriaImpuestosController::class, 'exportarFacturado'])->name('auditoria.exportar');
Route::get('/auditoria', [AuditoriaImpuestosController::class, 'index'])->name('auditoria.index');
Route::get('/', function () {
    return view('welcome');
});
