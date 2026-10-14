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

    'checks' => [
        'title' => 'Comprobaciones legales',
        'blocking' => 'Impeditivo',
        'warning' => 'Atención',
        'info' => 'Referencia',
    ],
    'deviation' => [
        'year' => 'El año del IPC no es el que fija la ley para esta vigencia: está aplicando el de :chosen cuando corresponde el de :statutory.',
        'city' => 'El IPC aplicado es el de :geography, y el artículo 20 se refiere al total nacional.',
        'pactado' => 'Las partes pactan aplicar el :pct % en lugar del IPC completo. El artículo 20 lo permite porque dice «hasta».',
        'excess' => 'El canon escrito supera el tope del artículo 20 en :amount.',
    ],
    'sections' => [
        'which_and_when' => 'Qué alquiler y desde cuándo',
        'with_which_number' => 'Con qué cifra',
        'party_decisions' => 'Lo que decidan las partes',
        'party_decisions_help' => 'Solo si pactan menos que el IPC o se apartan del artículo 20.',
        'party_decisions_why' => 'Esta combinación se aparta de lo que fija el artículo 20. Confírmelo y deja por escrito el motivo: es lo que sostiene el reajuste si el arrendatario lo impugna.',
        'party_decisions_reasons' => 'Motivos de la separación',
        'legal_checks' => 'Comprobaciones legales',
        'legal_checks_help' => 'Resultado de verificar los datos indicados contra los artículos 1, 18 y 20 de la Ley 820 de 2003. Solo se muestran las comprobaciones que tienen algo que decir.',
    ],

    'descriptions' => [
        'cap' => 'El tope se calcula sobre el canon que está rigiendo, no sobre el canon original: cada vez que el canon cambia, el artículo 20 reinicia los doce meses. Si el IPC lo excede, el sistema avisa y pide dejar por escrito el acuerdo.',
        'ipc' => 'El año del IPC se ofrece con el porcentaje al lado, porque el artículo 20 toma el año calendario anterior al de vigencia. El documento cita la fuente oficial del dato.',
        'anchor' => 'La cadena se recalcula desde el canon base del alquiler. Si corrige un reajuste, los siguientes se ajustan solos.',
    ],

    'table' => [
        'rental' => 'Alquiler',
        'previous_rent' => 'Canon anterior',
        'new_rent' => 'Canon reajustado',
        'ipc' => 'IPC aplicado',
        'legal_pct' => 'IPC legal',
        'legal_cap' => 'Tope según el IPC',
        'geography' => 'Serie del IPC',
        'effective_from' => 'Vigencia',
        'notified_on' => 'Comunicado',
        'status' => 'Estado',
        'sequence' => 'N.º en la cadena',
    ],

    'statuses' => [
        'within_cap' => 'Dentro del IPC',
        'exceeds_cap' => 'Excede el IPC',
    ],

    'states' => [
        'recorded' => 'Registrado',
        'notified' => 'Comunicado',
        'applied' => 'Aplicado',
    ],

    'notification_methods' => [
        'postal' => 'Servicio postal autorizado',
        'contractual' => 'Mecanismo pactado en el contrato',
        'in_person' => 'Entrega en mano con constancia',
        'email' => 'Correo electrónico',
        'label' => 'Medio de comunicación',
        'help' => 'El parágrafo 2 del artículo 20 reconoce el servicio postal autorizado y el mecanismo pactado en el contrato. Sin constancia del medio, la fecha por sí sola no acredita la comunicación y el incremento resulta inoponible.',
    ],

    'form' => [
        'rental_id' => 'Alquiler',
        'new_rent' => 'Canon reajustado',
        'new_rent_help' => 'El canon vigente es $:previous. Con el IPC de :year (:ipc %) el artículo 20 respalda $:cap.',
        'new_rent_help_above' => 'Supera el tope del artículo 20 en la diferencia que muestra la tabla.',
        'new_rent_help_below' => 'Queda por debajo del tope del artículo 20, lo que el artículo permite.',
        'new_rent_help_at_cap' => 'Coincide con el tope del artículo 20 (:cap).',
        'effective_from' => 'Vigencia desde',
        'effective_from_help' => 'El artículo 20 cuenta los doce meses desde el último cambio de canon, no desde el inicio del contrato.',
        'effective_from_overdue' => 'Este alquiler ya cumplió los doce meses. El plazo vencía el :date.',
        'effective_from_pick_rental' => 'Seleccione un alquiler y la fecha se propondrá: el aniversario de su último reajuste.',
        'business_day_note' => 'Ese aniversario cae en un día no hábil, así que se propone el :suggested para que el aviso por correo autorizado pueda entregarse. El plazo legal sigue siendo el :legal.',
        'ipc_year' => 'Año del IPC',
        'ipc_basis' => 'Base del IPC',
        'ipc_basis_help' => 'El artículo 20 se refiere al índice de precios al consumidor del total nacional. Aplicar el de una ciudad es posible, pero constituye una apartación de su texto y exige dejar constancia del motivo.',
        'geography_code' => 'Ciudad',
        'geography_code_no_year' => 'No hay datos del IPC de :geography para :year.',
        'pactado_pct' => 'Porcentaje pactado',
        'pactado_pct_help' => 'Se propone el porcentaje del año del IPC. El artículo 20 dice «hasta», así que las partes pueden acordar menos. Si escribe un porcentaje distinto al del IPC del año, el reajuste se aparta del artículo 20 y habrá que confirmarlo y dejar el motivo en las observaciones.',
        'ipc_outside_statutory_year' => 'Confirmo que me aparto del artículo 20',
        'ipc_outside_statutory_year_help' => 'Esta casilla aparece cuando el año del IPC, la ciudad o el monto se apartan de lo que fija el artículo 20. Marcarla no impide el registro: exige que el motivo quede consignado en las observaciones, que es lo que sostiene el reajuste en caso de impugnación.',
        'notes' => 'Observaciones',
        'notes_help' => 'Consigne aquí el acuerdo escrito, el motivo de apartarse del artículo 20 o cualquier otra circunstancia que el arrendatario deba conocer.',
        'notes_help_required' => 'Obligatorio en este caso: es lo que sostiene el reajuste si el arrendatario lo impugna.',
        'notification_method' => 'Medio de comunicación',
        'notification_date' => 'Fecha de comunicación',
        'select' => 'Seleccionar reajuste',
    ],

    'preview' => [
        'title' => 'Cálculo del reajuste',
        'current_rent' => 'Canon vigente',
        'current_rent_hint' => 'Monto sobre el cual se aplica el IPC. Es el canon del último reajuste aplicado, no el del contrato original.',
        'applied_ipc' => 'IPC aplicado',
        'applied_ipc_value' => 'Variación del :year para :geography',
        'increase' => 'Incremento aplicado',
        'cap' => 'Tope del artículo 20',
        'cap_hint' => 'Canon vigente más el incremento. Es lo que respalda el artículo 20.',
        'selected_rent' => 'Canon que se registrará',
        'selected_rent_hint' => 'El valor indicado en el campo anterior.',
        'above_cap' => 'Exceso sobre el tope',
        'above_cap_hint' => 'El artículo 20 lo formula como límite. Puede registrarlo si las partes lo acuerdan y lo documentan.',
        'below_cap' => 'Diferencia por debajo del tope',
        'national_geography' => 'total nacional',
    ],
    'placeholders' => [
        'notes' => 'Acuerdo escrito entre las partes firmado el 15 de enero de 2026, por el que se conviene aplicar la mitad del IPC.',
    ],

    'buttons' => [
        'create' => 'Registrar reajuste',
        'letter' => 'Carta de reajuste',
        'apply' => 'Aplicar al alquiler',
        'mark_notified' => 'Marcar como comunicado',
        'recalculate' => 'Recalcular cadena',
        'actions' => 'Acciones',
    ],

    'notifications' => [
        'created' => 'Reajuste registrado',
        'applied' => 'Canon actualizado',
        'needs_agreement' => 'El incremento supera lo que da el IPC. Queda registrado el motivo en las observaciones.',
        'recalculated' => 'Cadena recalculada',
        'missing_ipc' => 'Falta el IPC de diciembre del año anterior. El dato oficial aún no ha sido cargado en el sistema.',
    ],
];
