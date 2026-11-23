<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Auth\EmailVerificationRequest;

Route::get('/', function () {
    return redirect('/dashboard');
});

// Vista del archivo que el asistente genero (documento/CSV): el preview
// del chat la embebe en un iframe. Mismo control de acceso que la
// descarga — el token solo sirve al usuario que lo genero.
Route::get('/assistant/files/{token}', function (string $token) {
    $file = app(\App\Services\Assistant\AssistantActionExecutor::class)
        ->fileForDownload($token, (int) auth()->id());

    abort_if($file === null, 404);

    return response($file['content'], 200, [
        'Content-Type' => $file['mime'],
        'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
    ]);
})->middleware('auth')->name('assistant.file');

// Rutas de verificación de email
Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
    $request->fulfill();

    return redirect('/dashboard');
})->middleware(['auth', 'signed'])->name('verification.verify');
