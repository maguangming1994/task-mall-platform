<?php

namespace App\Modules\Legal\Services;

use App\Common\Support\ApiResponse;
use App\Models\User;
use App\Modules\Legal\Exceptions\LegalException;
use App\Modules\Legal\Models\LegalDocumentVersion;
use App\Modules\Legal\Models\UserLegalConsent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegalDocumentService
{
    /** @param array<int, string>|null $types
     * @return Collection<int, LegalDocumentVersion>
     */
    public function currentDocuments(?array $types = null): Collection
    {
        $documents = LegalDocumentVersion::query()
            ->where('status', 'published')
            ->whereNotNull('effective_at')
            ->where('effective_at', '<=', now())
            ->when($types !== null, fn ($query) => $query->whereIn('document_type', $types))
            ->orderBy('document_type')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get();

        return $documents->unique('document_type')->values();
    }

    /** @return Collection<int, LegalDocumentVersion> */
    public function pendingFor(User $user, ?string $scope = null): Collection
    {
        $types = $scope === null ? null : config("legal_documents.scopes.{$scope}");
        if ($scope !== null && ! is_array($types)) {
            throw ValidationException::withMessages(['scope' => ['未知的协议门槛范围。']]);
        }

        return $this->currentDocuments($types)
            ->filter(function (LegalDocumentVersion $document) use ($user): bool {
                if (! $document->consent_required) {
                    return false;
                }

                $latestAction = UserLegalConsent::query()
                    ->where('user_id', $user->id)
                    ->where('legal_document_version_id', $document->id)
                    ->latest('id')
                    ->value('action');

                return $latestAction !== 'accept';
            })
            ->values();
    }

    public function hasRequiredConsent(User $user, string $scope): bool
    {
        return $this->pendingFor($user, $scope)->isEmpty();
    }

    public function recordConsent(
        User $user,
        LegalDocumentVersion $document,
        string $action,
        string $clientPlatform,
        string $idempotencyKey,
        ?string $ip,
        ?string $userAgent,
        bool $acknowledgeServiceRestriction,
    ): UserLegalConsent {
        $idempotencyHash = $this->fingerprint($idempotencyKey);
        $existing = UserLegalConsent::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key_hash', $idempotencyHash)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $current = $this->currentDocuments([$document->document_type])->first();
        if ($current === null || $current->id !== $document->id) {
            throw ValidationException::withMessages([
                'legal_document_version_id' => ['只能操作当前已生效的协议版本。'],
            ]);
        }
        if ($action === 'withdraw' && ! $document->withdrawal_allowed) {
            throw ValidationException::withMessages(['action' => ['该协议不允许在线撤回。']]);
        }
        if ($action === 'withdraw' && $document->consent_required && ! $acknowledgeServiceRestriction) {
            throw new LegalException(
                'LEGAL_CONSENT_WITHDRAWAL_RESTRICTS_SERVICE',
                '撤回必要协议将限制任务、商城、社区或资产服务，请完成明确确认。',
                409,
                ['document_type' => $document->document_type, 'version' => $document->version],
            );
        }

        $latestAction = UserLegalConsent::query()
            ->where('user_id', $user->id)
            ->where('legal_document_version_id', $document->id)
            ->latest('id')
            ->value('action');
        if ($latestAction === $action) {
            throw ValidationException::withMessages(['action' => ['当前协议已经处于该状态。']]);
        }

        return DB::transaction(fn () => UserLegalConsent::query()->create([
            'user_id' => $user->id,
            'legal_document_version_id' => $document->id,
            'action' => $action,
            'client_platform' => $clientPlatform,
            'ip_hash' => $this->nullableFingerprint($ip),
            'user_agent_hash' => $this->nullableFingerprint($userAgent),
            'idempotency_key_hash' => $idempotencyHash,
            'request_id' => ApiResponse::requestId(),
        ]));
    }

    /** @return array<string, mixed> */
    public function presentDocument(LegalDocumentVersion $document, bool $current = true): array
    {
        return [
            'id' => $document->public_id,
            'document_type' => $document->document_type,
            'version' => $document->version,
            'title' => $document->title,
            'content_format' => $document->content_format,
            'content' => $document->content,
            'content_hash' => $document->content_hash,
            'effective_at' => $document->effective_at?->toIso8601String(),
            'current' => $current,
            'consent_required' => $document->consent_required,
            'withdrawal_allowed' => $document->withdrawal_allowed,
            'change_summary' => $document->change_summary,
        ];
    }

    /** @return array<string, mixed> */
    public function presentConsent(UserLegalConsent $consent): array
    {
        $consent->loadMissing('document');

        return [
            'id' => $consent->public_id,
            'legal_document_version_id' => $consent->document->public_id,
            'document_type' => $consent->document->document_type,
            'version' => $consent->document->version,
            'action' => $consent->action,
            'client_platform' => $consent->client_platform,
            'created_at' => $consent->created_at?->toIso8601String(),
        ];
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('auth_tokens.identity_hash_secret')));
    }

    private function nullableFingerprint(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $this->fingerprint($value);
    }
}
