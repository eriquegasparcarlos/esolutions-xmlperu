<?php

return array(

    'xmlperu' => array(

        /*
         * Token de EMPRESA (ability cpe:sign): el que emite comprobantes.
         * Lo devuelve dar de alta la empresa, o POST /v1/empresas/{ruc}/token.
         */
        'token' => env('XMLPERU_TOKEN', ''),

        /*
         * Token de CUENTA (ability empresas:manage): da de alta y administra
         * empresas. Es otro distinto, y a propósito: el token que llevas a un
         * punto de venta debe poder emitir, pero no crear empresas ni leer sus
         * credenciales.
         *
         * Solo hace falta si administras varias empresas desde tu sistema.
         */
        'token_cuenta' => env('XMLPERU_TOKEN_CUENTA', ''),

        /*
         * Secreto del webhook cpe.resuelto, para verificar cada entrega. Lo
         * devuelve configurar el webhook, una sola vez.
         */
        'webhook_secret' => env('XMLPERU_WEBHOOK_SECRET', ''),

    ),

);
