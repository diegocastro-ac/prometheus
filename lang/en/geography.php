<?php

/**
 * The markers that distinguish a city that has IPC data from one that does not.
 *
 * Only two words, and they live in their own file instead of being spread
 * across the forms that show them: "with IPC" is technical vocabulary of the
 * adjustment module, but the place where it appears is the property form, which
 * knows nothing about price indexes. Duplicating the text in both places is how
 * the two versions drift apart.
 */
return [
    'with_ipc' => 'with CPI',
    'without_ipc' => 'no CPI',
];
