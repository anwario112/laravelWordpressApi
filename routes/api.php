<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SimpleProducts;
use App\Http\Controllers\StoreImages;
use App\Http\Controllers\VariableProducts;
use App\Http\Controllers\OrderService;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


//wordpress data api
Route::post('WordpressProducts',[SimpleProducts::class,'PushData']);


//image api
Route::get('ftp/images', [StoreImages::class, 'viewImages']);
Route::get('attachImages', [StoreImages::class, 'AttachImageToProducts']);
Route::get('findSku', [StoreImages::class, 'checkImagesMatchSkus']);

//order api
Route::post('OrderService',[OrderService::class,'OrderDetails']);


//scheduled job
 Route::post('transferData',[SimpleProducts::class,'transferData']);

Route::get('/test', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'API is working!',
        'timestamp' => now(),
        'environment' => config('app.env')
    ]);
});

Route::get('/test-db', function () {
    try {
        // Try a simple query
        \DB::connection()->getPdo();

        return response()->json([
            'status' => 'success',
            'message' => 'Database connection is working!',
            'database' => config('database.default'),
            'timestamp' => now(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Database connection failed',
            'error' => $e->getMessage(),
            'timestamp' => now(),
        ], 500);
    }
});



