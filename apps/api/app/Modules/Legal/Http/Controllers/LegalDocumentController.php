<?php

namespace App\Modules\Legal\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Legal\Models\LegalDocumentVersion;
use App\Modules\Legal\Services\LegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalDocumentController extends Controller
{
    public function index(Request $request, LegalDocumentService $legal): JsonResponse
    {
        $data = validator([
            ...$request->all(),
            'client_platform' => $request->header('X-Client-Platform'),
        ], [
            'document_type' => ['sometimes', 'string', 'in:'.implode(',', config('legal_documents.types'))],
            'include_history' => ['sometimes', 'boolean'],
            'client_platform' => ['required', 'in:wechat-mini,h5-wechat,h5-browser'],
        ])->validate();
        $types = isset($data['document_type']) ? [$data['document_type']] : null;
        $current = $legal->currentDocuments($types);

        if (! ($data['include_history'] ?? false)) {
            return ApiResponse::success($current
                ->map(fn (LegalDocumentVersion $document) => $legal->presentDocument($document))
                ->values());
        }

        $currentIds = $current->pluck('id')->all();
        $documents = LegalDocumentVersion::query()
            ->where('status', 'published')
            ->whereNotNull('effective_at')
            ->where('effective_at', '<=', now())
            ->when($types !== null, fn ($query) => $query->whereIn('document_type', $types))
            ->orderBy('document_type')
            ->orderByDesc('effective_at')
            ->get();

        return ApiResponse::success($documents->map(
            fn (LegalDocumentVersion $document) => $legal->presentDocument(
                $document,
                in_array($document->id, $currentIds, true),
            ),
        )->values());
    }
}
