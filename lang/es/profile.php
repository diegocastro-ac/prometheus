<?php

return [
    'title' => 'Mi Perfil',

    'sections' => [
        'personal_info' => 'Información Personal',
        'change_password' => 'Cambiar Contraseña',
        'email_verification' => 'Verificación de Email',
    ],

    'form' => [
        'name' => 'Nombre completo',
        'email' => 'Correo electrónico',
        'document_type' => 'Tipo de documento',
        'document' => 'Número de documento',
        'phone_number' => 'Número de teléfono',
        'current_password' => 'Contraseña actual',
        'new_password' => 'Nueva contraseña',
        'new_password_confirmation' => 'Confirmar nueva contraseña',
    ],

    'document_types' => [
        'CC' => 'Cédula de Ciudadanía',
        'TI' => 'Tarjeta de Identidad',
        'CE' => 'Cédula de Extranjería',
        'PAS' => 'Pasaporte',
        'NIT' => 'NIT',
    ],

    'descriptions' => [
        'password_hint' => 'Deja estos campos en blanco si no deseas cambiar tu contraseña.',
    ],

    'buttons' => [
        'save' => 'Guardar cambios',
        'send_verification' => 'Enviar email de verificación',
        'confirm' => 'Sí, cambiar email',
        'cancel' => 'Cancelar',
    ],

    'notifications' => [
        'profile_updated' => 'Tu información ha sido actualizada correctamente.',
        'email_changed' => 'Tu nuevo correo electrónico debe ser verificado.',
        'password_changed' => 'Tu contraseña ha sido cambiada exitosamente.',
        'wrong_password' => 'La contraseña actual es incorrecta.',
        'new_password_required' => 'Debes ingresar una nueva contraseña.',
        'email_verified' => 'Tu correo electrónico está verificado.',
        'email_not_verified' => 'Tu correo electrónico no está verificado.',
        'already_verified' => 'Tu correo electrónico ya está verificado.',
        'verification_sent' => 'Se ha enviado un enlace de verificación a tu correo electrónico.',
    ],

    'modal' => [
        'confirm_email_change' => 'Confirmar cambio de correo electrónico',
        'confirm_email_description' => 'Al cambiar tu correo electrónico, deberás verificarlo nuevamente antes de poder usarlo. ¿Deseas continuar?',
    ],
];
