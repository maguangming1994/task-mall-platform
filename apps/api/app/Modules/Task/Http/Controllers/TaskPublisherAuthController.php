<?php

namespace App\Modules\Task\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Exceptions\AdminAuthException;
use App\Modules\Task\Models\TaskPublisher;
use App\Modules\Task\Models\TaskPublisherSession;
use App\Modules\Task\Services\TaskPublisherAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskPublisherAuthController extends Controller
{
    public function store(Request $request, TaskPublisherAuthService $auth): JsonResponse
    {
        $data = $request->validate(['account' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:200'], 'mfa_code' => ['sometimes', 'nullable', 'string', 'max:32']]);
        try {
            return ApiResponse::success($auth->login($data['account'], $data['password'], $data['mfa_code'] ?? null, $request->ip(), $request->userAgent()), 201);
        } catch (AdminAuthException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }
    }

    public function storeForUser(Request $request, TaskPublisherAuthService $auth): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            return ApiResponse::success($auth->loginUser($user, $request->ip(), $request->userAgent()), 201);
        } catch (AdminAuthException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }
    }

    public function destroy(Request $request, TaskPublisherAuthService $auth): JsonResponse
    {
        /** @var TaskPublisherSession $session */ $session = $request->attributes->get('publisher_session');
        $auth->logout($session);

        return ApiResponse::success(['revoked' => true]);
    }

    public function profile(Request $request, TaskPublisherAuthService $auth): JsonResponse
    {
        /** @var TaskPublisher $publisher */ $publisher = $request->user();

        return ApiResponse::success($auth->present($publisher));
    }
}
