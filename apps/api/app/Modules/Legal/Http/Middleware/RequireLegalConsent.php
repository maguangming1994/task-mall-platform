<?php

namespace App\Modules\Legal\Http\Middleware;

use App\Common\Support\ApiResponse;
use App\Models\User;
use App\Modules\Legal\Models\LegalDocumentVersion;
use App\Modules\Legal\Services\LegalDocumentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireLegalConsent
{
    public function __construct(private readonly LegalDocumentService $legal) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return ApiResponse::error('AUTH_REQUIRED', '请先登录。', 401);
        }

        $pending = $this->legal->pendingFor($user, $scope);
        if ($pending->isNotEmpty()) {
            return ApiResponse::error(
                'LEGAL_DOCUMENT_CONSENT_REQUIRED',
                '继续操作前需要接受当前生效的必要协议。',
                403,
                [
                    'scope' => $scope,
                    'documents' => $pending->map(fn (LegalDocumentVersion $document) => [
                        'id' => $document->public_id,
                        'document_type' => $document->document_type,
                        'version' => $document->version,
                        'title' => $document->title,
                    ])->values()->all(),
                ],
            );
        }

        return $next($request);
    }
}
