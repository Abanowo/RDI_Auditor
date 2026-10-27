<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobación de la cotización por el cliente desde el correo (enlace con token).
 */
class AddAprobacionPorTokenToCotizaciones extends Migration
{
    public function up()
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->string('token_aprobacion', 64)->nullable()->unique()->after('enviada_at');
            $table->timestamp('token_expira_at')->nullable()->after('token_aprobacion');
            $table->string('aprobada_por')->nullable()->after('aprobada_at');      // nombre de quien aprobó
            $table->string('aprobada_ip', 45)->nullable()->after('aprobada_por');
            $table->timestamp('cambios_solicitados_at')->nullable()->after('aprobada_ip');
            $table->text('respuesta_comentario')->nullable()->after('cambios_solicitados_at');
        });
    }

    public function down()
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropUnique(['token_aprobacion']);
            $table->dropColumn([
                'token_aprobacion', 'token_expira_at', 'aprobada_por', 'aprobada_ip',
                'cambios_solicitados_at', 'respuesta_comentario',
            ]);
        });
    }
}
