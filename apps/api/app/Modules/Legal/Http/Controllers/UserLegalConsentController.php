<?php

namespace App\Modules\Legal\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Legal\Exceptions\LegalException;
use App\Modules\Legal\Models\LegalDocumentVersion;
use App\Modules\Legal\Models\UserLegalConsent;
use App\Modules\Legal\Services\LegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserLegalConsentController extends Controller
{
    public function index(Request $request, LegalDocumentService $legal): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $consents = UserLegalConsent::query()
            ->with('document')
            ->where('user_id', $user->id)
            ->latest('id')
            ->get()
            ->map(fn (UserLegalConsent $consent) => $legal->presentConsent($consent));

        return ApiResponse::success($consents);
    }

    public function pending(Request $request, LegalDocumentService $legal): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['sometimes', 'string', 'in:'.implode(',', array_keys(config('legal_documents.scopes')))],
        ]);
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($legal->pendingFor($user, $data['scope'] ?? null)
            ->map(fn (LegalDocumentVersion $document) => $legal->presentDocument($document))
            ->values());
    }

    public function store(Request $request, LegalDocumentService $legal): JsonResponse
    {
        $data = validator([
            ...$request->all(),
            'client_platform' => $request->header('X-Client-Platform'),
            'idempotency_key' => $request->header('Idempotency-Key'),
        ], [
            'legal_document_version_id' => ['required', 'string', 'size:26'],
            'action' => ['required', 'in:accept,withdraw'],
            'acknowledge_service_restriction' => ['sometimes', 'boolean'],
            'client_platform' => ['required', 'in:wechat-mini,h5-wechat,h5-browser'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:191'],
        ])->validate();
        /** @var User $user */
        $user = $request->user();
        $document = LegalDocumentVersion::query()
            ->where('public_id', $data['legal_document_version_id'])
            ->firstOrFail();
        try {
            $consent = $legal->recordConsent(
                $user,
                $document,
                $data['action'],
                $data['client_platform'],
                $data['idempotency_key'],
                $request->ip(),
                $request->userAgent(),
                (bool) ($data['acknowledge_service_restriction'] ?? false),
            );
        } catch (LegalException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }

        return ApiResponse::success($legal->presentConsent($consent), 201, '协议状态已记录。');
    }
}
