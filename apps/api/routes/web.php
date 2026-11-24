<?php

use App\Modules\Auth\Models\WechatVerificationFile;
use App\Modules\Community\Http\Controllers\ShortLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * 短链跳转：根路径 /s/{code}，不暴露 /api/v1 前缀。
 */
Route::get('/s/{code}', [ShortLinkController::class, 'post'])
    ->middleware('throttle:60,1')
    ->where('code', '[a-z2-9]{6}')
    ->name('short-link.post');

Route::get('/{filename}', function (string $filename) {
    abort_unless(preg_match('/^MP_verify_[A-Za-z0-9_-]{6,64}\.txt$/', $filename), 404);
    $file = WechatVerificationFile::query()->where('filename', $filename)->firstOrFail();

    return response($file->content."\n", 200, [
        'Content-Type' => 'text/plain; charset=utf-8',
        'Cache-Control' => 'public, max-age=60',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->where('filename', 'MP_verify_[A-Za-z0-9_-]{6,64}\.txt');
