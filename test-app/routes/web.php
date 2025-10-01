<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-account-switcher', function () {
    return view('test-account-switcher');
})->name('test.account-switcher');
