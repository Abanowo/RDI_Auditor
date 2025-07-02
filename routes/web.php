<?php

use App\Http\Controllers\AuditController;
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
Route::get('/documentos/ver', [DocumentoController::class, 'mostrarPdf'])->name('documentos.ver');
Route::get('/auditoria', [AuditController::class, 'index'])->name('auditoria.index');
Route::get('/', function () {
    return view('welcome');
});
