<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Assets\Models\WalletRecharge;
use App\Modules\Assets\Models\WalletRechargeCard;
use App\Modules\Assets\Services\WalletRechargeService;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Upload\Models\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminWalletRechargeController extends Controller
{
    public function index(Request $request, WalletRechargeService $service): JsonResponse
    {
        $status = $request->string('status')->toString();
        $items = WalletRecharge::query()->with('user')->when($status !== '', fn ($q) => $q->where('status', $status))->latest('id')->limit(100)->get()->map(fn ($item) => $service->present($item));
        return ApiResponse::success(['items' => $items]);
    }

    public function review(Request $request, WalletRecharge $walletRecharge, WalletRechargeService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['required', 'string', 'min:2', 'max:500']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $result = $service->review($admin, $walletRecharge, $data['decision'], $data['note']);
        $presented = $service->present($result);
        $audit->record($admin, 'wallet_recharge.'.$data['decision'], 'wallet_recharge', $result->public_id, null, $presented, $data['note']);
        return ApiResponse::success($presented);
    }

    public function proof(WalletRecharge $walletRecharge, UploadedFile $file): StreamedResponse
    {
        abort_unless($walletRecharge->method === 'offline' && in_array($file->public_id, $walletRecharge->offline_proof_file_ids ?? [], true) && $file->purpose === 'offline_recharge' && $file->status === 'clean', 404);
        return response()->streamDownload(function () use ($file): void {
            $stream = Storage::disk($file->disk)->readStream($file->quarantine_path);
            if (is_resource($stream)) { fpassthru($stream); fclose($stream); }
        }, $file->original_name, ['Content-Type' => $file->detected_mime ?? $file->declared_mime]);
    }

    public function cards(Request $request, WalletRechargeService $service): JsonResponse
    {
        $status = $request->string('status')->toString();
        $items = WalletRechargeCard::query()->when($status !== '', fn ($q) => $q->where('status', $status))->latest('id')->limit(100)->get()->map(fn ($item) => $service->presentCard($item));
        return ApiResponse::success(['items' => $items]);
    }

    public function createCards(Request $request, WalletRechargeService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['amount_cents' => ['required', 'integer', 'min:100', 'max:5000000'], 'quantity' => ['required', 'integer', 'min:1', 'max:100'], 'expires_at' => ['nullable', 'date', 'after:now']]);
        /** @var AdminUser $admin */ $admin = $request->user();
        $result = $service->createCards($admin, $data['amount_cents'], $data['quantity'], $data['expires_at'] ?? null);
        $audit->record($admin, 'wallet_recharge_card.batch_create', 'wallet_recharge_card', 'batch', null, ['amount_cents' => $data['amount_cents'], 'quantity' => $data['quantity'], 'expires_at' => $data['expires_at'] ?? null], '创建充值卡密批次。');
        return ApiResponse::success($result, 201);
    }
}
