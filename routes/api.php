<?php

use App\Http\Controllers\NewsController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/news/ingest', [NewsController::class, 'ingest'])->middleware('throttle:30,1');
