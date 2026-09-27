<?php

namespace App\Modules\Account\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Account\Services\UserProfileService;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserProfileController extends Controller
{
    public function update(Request $request, UserProfileService $profiles, AuthService $auth): JsonResponse
    {
        if (is_string($request->input('nickname'))) {
            $request->merge(['nickname' => Str::trim($request->input('nickname'))]);
        }

        $data = $request->validate([
            'nickname' => ['sometimes', 'string', 'min:1', 'max:80'],
            'avatar_file_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);
        if ($data === []) {
            return ApiResponse::error('VALIDATION_FAILED', '至少提交一个需要修改的资料字段。', 422);
        }

        return ApiResponse::success($auth->presentUser($profiles->update($request->user(), $data)));
    }
}
