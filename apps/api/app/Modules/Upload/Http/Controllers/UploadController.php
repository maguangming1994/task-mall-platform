<?php

namespace App\Modules\Upload\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Upload\Models\UploadedFile;
use App\Modules\Upload\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UploadController extends Controller
{
    public function ticket(Request $request, UploadService $uploads): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1'],
            'purpose' => ['required', 'in:avatar,task_submission,task_report,community,refund_evidence,return_shipping,offline_recharge'],
        ]);
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        if (strlen($idempotencyKey) < 12 || strlen($idempotencyKey) > 128) {
            return ApiResponse::error('IDEMPOTENCY_KEY_REQUIRED', '请提供 12 至 128 字符的幂等键。', 422);
        }
        /** @var User $user */
        $user = $request->user();
        $result = $uploads->createTicket($user, $data, $idempotencyKey);

        return ApiResponse::success($result['ticket'], 201, '私有上传票据已创建。');
    }

    public function proxyUpload(Request $request, UploadedFile $file, UploadService $uploads): JsonResponse
    {
        return ApiResponse::success($this->present($uploads->proxyUpload($request, $file)), 200, '文件已写入隔离区。');
    }

    public function complete(Request $request, UploadedFile $file, UploadService $uploads): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($this->present($uploads->complete($user, $file)), 200, '上传完成，安全扫描状态已更新。');
    }

    public function show(Request $request, UploadedFile $file, UploadService $uploads): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $uploads->authorizeOwner($user, $file);

        return ApiResponse::success($this->present($file));
    }

    public function download(Request $request, UploadedFile $file, UploadService $uploads): StreamedResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $uploads->authorizeOwner($user, $file);
        if ($file->purpose === 'privacy_export' && $file->ticket_expires_at?->isPast()) {
            return ApiResponse::error('FILE_EXPIRED', '个人数据副本已过期，请重新发起申请。', 410);
        }
        if ($file->status !== 'clean') {
            return ApiResponse::error('FILE_NOT_AVAILABLE', '文件通过安全扫描后才可访问。', 409);
        }

        return response()->streamDownload(function () use ($file): void {
            $stream = Storage::disk($file->disk)->readStream($file->quarantine_path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $file->original_name, ['Content-Type' => $file->detected_mime ?? $file->declared_mime]);
    }

    /** @return array<string,mixed> */
    private function present(UploadedFile $file): array
    {
        return [
            'file_id' => $file->public_id,
            'purpose' => $file->purpose,
            'filename' => $file->original_name,
            'declared_mime' => $file->declared_mime,
            'detected_mime' => $file->detected_mime,
            'size' => $file->actual_size ?? $file->declared_size,
            'status' => $file->status,
            'rejection_code' => $file->rejection_code,
            'sha256' => $file->sha256,
            'scanned_at' => $file->scanned_at?->toIso8601String(),
        ];
    }
}
