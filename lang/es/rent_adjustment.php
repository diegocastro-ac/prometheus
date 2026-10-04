<?php

return [
    'title' => 'Reajustes del canon',
    'navigation_label' => 'Reajustes',

    'navigation' => [
        'group' => 'Administración',
        'labels' => [
            'singular' => 'reajuste',
            'plural' => 'reajustes',
        ],
    ],

    'sections' => [
        'general' => 'Datos del reajuste',
        'legal' => 'Tope legal',
    ],

    'descriptions' => [
        'legal' => 'El artículo 20 de la Ley 820 de 2003 limita el incremento al IPC del año calendario inmediatamente anterior y exige comunicar el monto y la fecha de vigencia.',
        'cap' => 'El tope se calcula con el canon vigente y el IPC del año anterior. Un incremento por encima del tope solo opera con acuerdo escrito.',
        'ipc' => 'El IPC se registra una vez por año con su fuente oficial. El documento cita esa fuente.',
    ],

    'table' => [
        'rental' => 'Alquiler',
        'previous_rent' => 'Canon anterior',
        'new_rent' => 'Canon reajustado',
        'increase' => 'Incremento',
        'ipc' => 'IPC aplicado',
        'legal_cap' => 'Tope legal',
        'effective_from' => 'Vigencia',
        'notified_on' => 'Comunicado',
        'status' => 'Estado',
    ],

    'statuses' => [
        'within_cap' => 'Dentro del tope',
        'exceeds_cap' => 'Excede el tope',
        'notified' => 'Comunicado',
        'pending_notification' => 'Sin comunicar',
    ],

    'form' => [
        'rental_id' => 'Alquiler',
        'new_rent' => 'Canon reajustado',
        'effective_from' => 'Vigencia desde',
        'ipc_year' => 'Año del IPC',
        'ipc_outside_statutory_year' => 'Apartarme del año que fija la ley',
        'ipc_outside_statutory_year_help' => 'El artículo 20 obliga a usar el IPC del año calendario anterior al de la vigencia. Si el año elegido no es ese, márquelo y explique el motivo en las observaciones; sin ambas cosas el registro se rechaza.',
        'notes' => 'Observaciones',
        'notes_help' => 'Si el incremento supera el tope, registre aquí el acuerdo escrito. Sin esa nota el ajuste no se aplica al alquiler.',
    ],

    'placeholders' => [
        'notes' => 'Acuerdo escrito entre las partes firmado el 15 de enero de 2026.',
    ],

    'buttons' => [
        'create' => 'Registrar reajuste',
        'letter' => 'Carta de reajuste',
        'apply' => 'Aplicar al alquiler',
        'mark_notified' => 'Marcar como comunicado',
    ],

    'notifications' => [
        'created' => 'Reajuste registrado',
        'applied' => 'Canon actualizado',
        'notified' => 'Reajuste marcado como comunicado',
        'needs_agreement' => 'El incremento supera el tope del IPC. Registre el acuerdo escrito en las observaciones para poder aplicarlo.',
    ],
];
