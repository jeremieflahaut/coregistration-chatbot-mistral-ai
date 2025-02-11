<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::get('/chat', function () {
    return view('chatbot'); // Vue Blade du chatbot
});

// Routes API pour gérer le chatbot


