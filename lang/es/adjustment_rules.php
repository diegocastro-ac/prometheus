<?php

/**
 * Los mensajes de las nueve reglas del articulo 20, 18 y 1.
 *
 * POR QUE ESTAN AQUI Y NO EN LA CLASE
 * ------------------------------------
 * Cuando estaban escritos dentro de las reglas tenian tres problemas que no se
 * pueden arreglar moviendolos de sitio, sino cambiandolos:
 *
 * 1. No tenian version en ingles. La regla es la misma para los dos idiomas;
 *    solo el texto estaba atado al idioma de quien lee el codigo.
 * 2. No tenian tildes. "articulo", "esta", "podria". Escritas a mano se nota
 *    que el registro formal no se cuido, y eso es justo lo que el usuario
 *    nota cuando lee un texto legal.
 * 3. Contenian detalle tecnico. Cuatro de ellas terminaban con "Ejecute php
 *    artisan ipc:sync", que es la instruccion de consola de quien administra el
 *    sistema filtrada en un mensaje que puede ver un arrendatario. Eso se
 *    separo: el detalle tecnico quedo en el servicio, donde lo lee el operador,
 *    y aqui solo queda lo que la regla dice.
 *
 * EL REGISTRO
 * -----------
 * Uniforme de "usted", que es el que corresponde a un texto que se dirige a
 * quien administra un alquiler ajeno. Sin mayusculas enfaticas, sin "Ojo:", sin
 * diminutivos. Los imperativos van como "Marque", "Seleccione", "Registre",
 * "Corrija", que es como un formulario juridico le pide las cosas.
 *
 * LA ESTRUCTURA DE CADA MENSAJE
 * -----------------------------
 * El mensaje explica la situacion y, cuando el usuario puede hacer algo, dice
 * cual es la accion. El articulo va aparte, porque lo anade el que arma el
 * texto final: asi el mismo texto sirve para el panel interno y para una
 * notificacion donde el articulo ya se menciona en otro lugar.
 */
return [

    'has_ipc_data' => [
        // Sin dato de diciembre, no hay tope que calcular ni carta que
        // sustente. El mensaje dice además cuándo llega el dato: la duda
        // real de quien llena el formulario no es "por qué falta", sino
        // "cuándo va a estar".
        'missing' => 'El dato de diciembre de :year no está disponible aún. Se publica alrededor del 15/01/:next_year; vuelva a intentarlo tras la sincronización.',
        'missing_context' => 'El dato de diciembre de :geography de :year no está disponible aún. Se publica alrededor del 15/01/:next_year; vuelva a intentarlo tras la sincronización.',
        'no_variation' => 'El dato del DANE para diciembre de :year está cargado, pero no incluye la variación anual, que es el dato que requiere el artículo 20.',
        'no_variation_context' => 'El dato del DANE para :geography de diciembre de :year está cargado, pero no incluye la variación anual, que es el dato que requiere el artículo 20.',

        /*
        | Estas tres son para excepciones, que se muestran como una notificacion
        | en lugar de una linea del panel de comprobaciones. Son mas cortas porque
        | el contexto ya se sabe: el usuario acaba de pedir una operacion y esta
        | es la razon por la que no se pudo hacer.
        |
        | Tampoco dicen COMO obtener el dato. Ver la nota de cabecera.
        */
        'missing_short' => 'No se encuentra el IPC de :geography para diciembre de :year. Se publica alrededor del 15/01/:next_year; vuelva a intentarlo tras la sincronización.',
        'missing_city' => 'Se seleccionó el IPC de una ciudad sin indicar cuál. Seleccione la ciudad en el formulario del reajuste.',
        'missing_index' => 'No se encuentra el índice del IPC para el período :period en :geography.',
    ],

    'is_residential_scope' => [
        // El artículo 1 limita la ley a vivienda urbana. En un local comercial
        // el IPC es referencia, no tope. Se explica sin alarmar y sin suponer
        // que la clasificacion este mal.
        'commercial' => 'Este alquiler está registrado como comercial. La Ley 820 de 2003 se aplica a la vivienda urbana, por lo que en un inmueble comercial el canon lo determina el mercado y el porcentaje calculado es una referencia, no un límite legal. Si la clasificación no corresponde al inmueble, corríjala antes de continuar.',
    ],

    'within_twelve_months' => [
        'due' => 'El alquiler cumple los doce meses el :due_date. Es a partir de esa fecha que este reajuste puede regir sin apartarse del artículo 20.',
        'acknowledged_early' => 'Este reajuste se registra antes de que se cumplan los doce meses bajo un mismo precio, que vencen el :due_date. El motivo queda consignado en las observaciones.',
        'early' => 'El último cambio de canon se produjo hace menos de doce meses: este reajuste podría regir a partir del :due_date. El artículo 20 cuenta los doce meses bajo un mismo precio. Marque la confirmación si el reajuste debe aplicarse antes de esa fecha y consigne el motivo en las observaciones.',
    ],

    'statutory_calendar_year' => [
        'acknowledged' => 'Se aplica el IPC de :chosen cuando el artículo 20 fija el de :statutory para esta vigencia. El motivo queda consignado en las observaciones.',
        'mismatch' => 'El artículo 20 fija el IPC de :statutory para un reajuste con vigencia desde el :effective_from, y se ha seleccionado el de :chosen. Marque la confirmación si ese es el ajuste que desea registrar y consigne el motivo en las observaciones.',
    ],

    'city_ipc_requires_acknowledgement' => [
        'national_reference' => 'Para el total nacional, el IPC del año es :pct %.',
        'same_as_national' => 'Se aplica el IPC de :geography en lugar del total nacional, pero su porcentaje coincide con el nacional (:pct %), así que el resultado cumple con el artículo 20. Queda como referencia de la serie usada.',
        'acknowledged' => 'El reajuste se calcula con el IPC de :geography en lugar del total nacional. El motivo queda consignado en las observaciones.',
        'warning' => 'Se está aplicando el IPC de :geography en lugar del total nacional. El artículo 20 se refiere al índice de precios al consumidor sin distinguir ciudades, por lo que esta decisión constituye una apartación de su texto. Marque la confirmación si es la que desea registrar. :national_reference',
    ],

    'commercial_value_within_catastral_ceiling' => [
        'warning' => 'El valor comercial declarado (:commercial) supera el doble del avalúo catastral (:catastral), que es el techo que fija el artículo 18. El uno por ciento del canon se calcularía sobre un valor que la ley no admite para ese efecto.',
    ],

    'within_one_percent_of_commercial_value' => [
        'warning' => 'El canon de :rent supera el uno por ciento del valor comercial declarado, que es de :commercial. El artículo 18 limita el canon completo a ese uno por ciento, por lo que el reajuste lo dejaría :excess por encima del límite. El tope en este caso lo fijan las partes y no el IPC, de modo que no se corrige bajando el porcentaje.',
    ],

    'within_cap_or_acknowledged' => [
        'acknowledged' => 'El incremento supera el IPC en :excess. El motivo queda consignado en las observaciones. Tenga presente que el artículo 20 exige comunicar el monto y la fecha, y que el pago no sane la falta de aviso.',
        'warning' => 'El canon solicitado supera en :excess el que resulta del IPC de :year. El artículo 20 lo formula como límite, pero la práctica admite acordar un incremento mayor cuando las partes lo convienen y lo documentan. Registre el acuerdo en las observaciones para que la comunicación al arrendatario lo refleje.',
    ],

    'notification_has_valid_means' => [
        'valid' => 'La comunicación se realizó por :method, que es un medio expresamente reconocido por el artículo 20. Queda constancia de la fecha y del medio en la carta.',
        'invalid' => 'El artículo 20 reconoce el servicio postal autorizado y el mecanismo pactado en el contrato. El medio indicado (:method) no está entre ellos: es válido si el contrato estableció ese canal, y conviene que quede por escrito. Tenga presente que el pago del canon no sane la falta de comunicación, conforme lo dispone el parágrafo 3.',
    ],

];
