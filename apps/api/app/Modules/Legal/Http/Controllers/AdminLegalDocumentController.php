<?php

namespace App\Modules\Legal\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Common\Support\RichTextSanitizer;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Legal\Models\LegalDocumentVersion;
use App\Modules\Legal\Services\LegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminLegalDocumentController extends Controller
{
    public function index(Request $request, LegalDocumentService $legal): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['sometimes', 'in:'.implode(',', config('legal_documents.types'))],
            'status' => ['sometimes', 'in:draft,published'],
        ]);
        $documents = LegalDocumentVersion::query()
            ->when(isset($data['document_type']), fn ($query) => $query->where('document_type', $data['document_type']))
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->latest('id')
            ->get()
            ->map(fn (LegalDocumentVersion $document) => [
                ...$legal->presentDocument($document, false),
                'status' => $document->status,
                'published_at' => $document->published_at?->toIso8601String(),
            ]);

        return ApiResponse::success(['items' => $documents]);
    }

    public function store(Request $request, AuditLogger $audit, LegalDocumentService $legal, RichTextSanitizer $richText): JsonResponse
    {
        $data = $this->definition($request, $richText);
        /** @var AdminUser $admin */
        $admin = $request->user();
        $document = LegalDocumentVersion::query()->create([
            ...$data,
            'content_format' => $data['content_format'] ?? 'rich_text_html',
            'status' => 'draft',
            'created_by_admin_id' => $admin->id,
        ]);
        $presented = [...$legal->presentDocument($document, false), 'status' => 'draft'];
        $audit->record($admin, 'legal_document.create_draft', 'legal_document_version', $document->public_id, null, $presented);

        return ApiResponse::success($presented, 201, '协议草稿已创建。');
    }

    public function update(Request $request, LegalDocumentVersion $legalDocument, AuditLogger $audit, LegalDocumentService $legal, RichTextSanitizer $richText): JsonResponse
    {
        if ($legalDocument->status !== 'draft') {
            return ApiResponse::error('LEGAL_DOCUMENT_IMMUTABLE', '已发布协议不可修改，请创建新版本。', 409);
        }
        $data = $this->definition($request, $richText);
        /** @var AdminUser $admin */ $admin = $request->user();
        $before = [...$legal->presentDocument($legalDocument, false), 'status' => $legalDocument->status];
        $legalDocument->update([...$data, 'content_format' => $data['content_format'] ?? 'rich_text_html']);
        $after = [...$legal->presentDocument($legalDocument->fresh(), false), 'status' => 'draft'];
        $audit->record($admin, 'legal_document.update_draft', 'legal_document_version', $legalDocument->public_id, $before, $after, $data['change_summary']);

        return ApiResponse::success($after);
    }

    public function publish(
        Request $request,
        LegalDocumentVersion $legalDocument,
        AuditLogger $audit,
        LegalDocumentService $legal,
    ): JsonResponse {
        $data = $request->validate([
            'confirm_legal_review' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);
        if ($legalDocument->status !== 'draft') {
            return ApiResponse::error('LEGAL_DOCUMENT_STATE_INVALID', '只有草稿协议可以发布。', 409);
        }
        /** @var AdminUser $admin */
        $admin = $request->user();
        $before = [...$legal->presentDocument($legalDocument, false), 'status' => $legalDocument->status];
        $legalDocument->update(['status' => 'published', 'published_at' => now()]);
        $after = [...$legal->presentDocument($legalDocument->fresh(), false), 'status' => 'published'];
        $audit->record(
            $admin,
            'legal_document.publish',
            'legal_document_version',
            $legalDocument->public_id,
            $before,
            $after,
            $data['reason'],
        );

        return ApiResponse::success($after);
    }

    /** @return array<string,mixed> */
    private function definition(Request $request, RichTextSanitizer $richText): array
    {
        $versionRule = Rule::unique('legal_document_versions', 'version')->where(fn ($query) => $query->where('document_type', $request->input('document_type')));
        if ($request->route('legalDocument') instanceof LegalDocumentVersion) {
            $versionRule->ignore($request->route('legalDocument')->id);
        }
        $data = $request->validate([
            'document_type' => ['required', 'in:'.implode(',', config('legal_documents.types'))],
            'version' => ['required', 'string', 'max:40', $versionRule],
            'title' => ['required', 'string', 'max:160'],
            'content_format' => ['sometimes', 'in:markdown,rich_text_html'],
            'content' => ['required', 'string', 'min:20', 'max:500000'],
            'consent_required' => ['required', 'boolean'],
            'withdrawal_allowed' => ['required', 'boolean'],
            'effective_at' => ['required', 'date'],
            'change_summary' => ['required', 'string', 'min:4', 'max:500'],
        ]);
        if (($data['content_format'] ?? 'rich_text_html') === 'rich_text_html') {
            $data['content'] = $richText->sanitize($data['content']);
            if ($richText->toPlainText($data['content']) === '') {
                throw ValidationException::withMessages(['content' => ['协议正文不能为空。']]);
            }
        }

        return $data;
    }
}
