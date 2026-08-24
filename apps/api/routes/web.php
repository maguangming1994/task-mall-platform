<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Auth\Models\WechatVerificationFile;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/{filename}', function (string $filename) {
    abort_unless(preg_match('/^MP_verify_[A-Za-z0-9_-]{6,64}\.txt$/', $filename), 404);
    $file = WechatVerificationFile::query()->where('filename', $filename)->firstOrFail();

    return response($file->content."\n", 200, [
        'Content-Type' => 'text/plain; charset=utf-8',
        'Cache-Control' => 'public, max-age=60',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->where('filename', 'MP_verify_[A-Za-z0-9_-]{6,64}\.txt');
