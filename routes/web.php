<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\MapController;
use App\Models\Location;
use App\Http\Controllers\CustomerMapController;
use App\Http\Controllers\DriverController;

Route::get('/', [MapController::class, 'index']);
Route::get('/customer-map', [CustomerMapController::class, 'index']);
Route::get('/driver-pos/{id}', [DriverController::class, 'index']);

Route::post('/add-location', [MapController::class, 'addLocation']);
Route::get('/locations', function () {
    return response()->json(Location::all());
});