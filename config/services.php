<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DANE - IPC
    |--------------------------------------------------------------------------
    |
    | El DANE no publica una API REST oficial del IPC. Lo que alimenta su
    | "Consulta dinamica del IPC" es el servicio CDA de Pentaho, sobre el que
    | no hay garantia de estabilidad: los nombres de las tablas internas pueden
    | cambiar sin aviso. Por eso el sistema sincroniza a la base de datos local
    | y calcula siempre desde la copia, nunca en tiempo real.
    |
    | El adaptador (app/Adapters/Ipc) es lo unico que conoce estos valores.
    | Si el DANE cambia el esquema se corrige aqui y en config/dane_ipc_sql.php,
    | y nada mas.
    |
    */

    'dane_ipc' => [
        'base_url' => env('DANE_IPC_BASE_URL', 'https://apps.dane.gov.co/pentaho/plugin/cda/api'),
        'cda_path' => env('DANE_IPC_CDA_PATH', '/public/IPC/IPCV7.cda'),
        'timeout' => (int) env('DANE_IPC_TIMEOUT', 90),

        // El DANE responde 502 de forma intermitente. Se reintenta con espera
        // creciente y, si los reintentos se agotan, se conserva la ultima serie
        // buena: un fallo de red deja la app desactualizada, nunca sin IPC.
        'retries' => (int) env('DANE_IPC_RETRIES', 4),
        'retry_delay_ms' => (int) env('DANE_IPC_RETRY_DELAY_MS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Festivos para el calendario de dias habiles
    |--------------------------------------------------------------------------
    |
    | Fuente publica de festivos (Nager.Date) que el Adapter consulta para correr
    | el vencimiento de una factura cuando cae en dia no habil. La respuesta se
    | cachea por anio y, si el servicio no responde, se degrada a fin de semana.
    |
    */

    'holidays' => [
        'base_url' => env('HOLIDAYS_BASE_URL', 'https://date.nager.at/api/v3'),
        'country' => env('HOLIDAYS_COUNTRY', 'CO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gemini - Copiloto del arrendador
    |--------------------------------------------------------------------------
    |
    | El asistente usa la API gratuita de Google AI Studio. La IA solo
    | interpreta la pregunta y clasifica la intencion; las cifras siempre las
    | calculan los servicios del sistema. Sin GEMINI_API_KEY la pagina del
    | asistente muestra un aviso y el resto de la app funciona igual.
    |
    */

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-3.5-flash-lite'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 10),

        // Ruta opcional al bundle de CAs (cacert.pem). En Windows PHP no usa
        // el almacen del sistema: sin curl.cainfo configurado, toda llamada
        // HTTPS falla con "cURL error 60". Apuntar aqui permite resolverlo sin
        // tocar php.ini. Si queda vacio se usa la configuracion de PHP.
        'ca_bundle' => env('GEMINI_CA_BUNDLE'),
    ],

];
