<?php

use App\Http\Controllers\Api\BookController;
use Illuminate\Support\Facades\Route;

Route::prefix('books')
    ->name('books.')
    ->group(function (): void {
        Route::get('/', [BookController::class, 'index'])->name('index');
        Route::get('{book}/download', [BookController::class, 'download'])
            ->where('book', '[A-Za-z0-9\-_]+')
            ->name('download');
        Route::get('{book}/cover', [BookController::class, 'cover'])
            ->where('book', '[A-Za-z0-9\-_]+')
            ->name('cover');
    });

Route::get('stats', [BookController::class, 'stats'])->name('stats');
