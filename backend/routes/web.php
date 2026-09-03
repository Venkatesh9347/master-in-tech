<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/reset-password/{token}', function (Request $request, string $token) {
    return redirect()->away(
        rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/')
        . '/reset-password?token=' . urlencode($token)
        . '&email=' . urlencode((string) $request->query('email'))
    );
})->name('password.reset');
