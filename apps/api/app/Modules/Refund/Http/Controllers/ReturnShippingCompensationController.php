<?php

namespace App\Modules\Refund\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Refund\Models\ReturnShippingCompensation;
use App\Modules\Refund\Services\ReturnShippingCompensationService;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReturnShippingCompensationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ReturnShippingCompensation::query()->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return ApiResponse::success(['items' => $query->limit(100)->get()->map(fn ($item) => $this->data($item))]);
    }

    public function review(Request $request, ReturnShippingCompensation $compensation, ReturnShippingCompensationService $service): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'approved_amount' => ['nullable', 'integer', 'min:1'], 'note' => ['required', 'string', 'max:500']]);
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 12, 422);
        /** @var AdminUser $admin */
        $admin = $request->user();

        return ApiResponse::success($this->data($service->review($admin, $compensation, $data, $key)));
    }

    public function proof(ReturnShippingCompensation $compensation): StreamedResponse|JsonResponse
    {
        $file = UploadedFile::query()->findOrFail($compensation->payment_proof_file_id);
        abort_unless($file->purpose === 'return_shipping', 404);
        if ($file->status !== 'clean') {
            return ApiResponse::error('FILE_NOT_AVAILABLE', '付款凭证通过安全扫描后才可预览。', 409);
        }

        return response()->streamDownload(function () use ($file): void {
            $stream = Storage::disk($file->disk)->readStream($file->quarantine_path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $file->original_name, ['Content-Type' => $file->detected_mime ?? $file->declared_mime, 'Cache-Control' => 'private, no-store']);
    }

    public static function data(ReturnShippingCompensation $item): array
    {
        return ['id' => $item->public_id, 'source_type' => $item->source_type, 'requested_amount' => $item->requested_amount, 'approved_amount' => $item->approved_amount, 'status' => $item->status, 'carrier_code' => $item->carrier_code, 'tracking_no' => $item->tracking_no, 'payment_proof_file_id' => UploadedFile::query()->whereKey($item->payment_proof_file_id)->value('public_id'), 'review_note' => $item->review_note, 'paid_at' => $item->paid_at?->toIso8601String(), 'created_at' => $item->created_at->toIso8601String()];
    }
}
