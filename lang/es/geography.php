<?php

/**
 * Los marcadores que distinguen una ciudad con IPC de una sin IPC.
 *
 * Son solo dos palabras y hay una razon para que vivan en su propio archivo en
 * lugar de repartirse en los formularios que las usan: el significado tecnico
 * de "con IPC" es del modulo de reajustes, pero el lugar donde se ve es el
 * formulario de propiedades, que no sabe nada de indices de precios.
 * Duplicar el texto en los dos sitios es como se desincronizan.
 */
return [
    'with_ipc' => 'con IPC',
    'without_ipc' => 'sin IPC',
];
