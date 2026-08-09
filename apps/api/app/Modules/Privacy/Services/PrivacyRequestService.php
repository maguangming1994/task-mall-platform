<?php

namespace App\Modules\Privacy\Services;

use App\Models\User;
use App\Modules\Privacy\Exceptions\PrivacyException;
use App\Modules\Privacy\Jobs\BuildPrivacyExport;
use App\Modules\Privacy\Models\LegalHold;
use App\Modules\Privacy\Models\PrivacyRequest;

class PrivacyRequestService
{
    public function create(User $user, array $data, string $idempotencyKey): PrivacyRequest
    {
        $hash = $this->fingerprint($idempotencyKey);
        $existing = PrivacyRequest::query()->where('user_id', $user->id)->where('idempotency_key_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }
        $active = PrivacyRequest::query()->where('user_id', $user->id)->where('request_type', $data['request_type'])->whereIn('status', ['submitted', 'verifying', 'processing'])->first();
        if ($active !== null) {
            throw new PrivacyException('PRIVACY_REQUEST_ALREADY_ACTIVE', '同类型隐私请求正在处理中。', 409);
        }
        $blockers = [];
        if ($data['request_type'] === 'deletion' && LegalHold::query()->where('object_type', 'user')->where('object_id', $user->public_id)->whereNull('released_at')->exists()) {
            $blockers[] = 'legal_hold';
        }
        $request = PrivacyRequest::query()->create([
            'user_id' => $user->id, 'request_type' => $data['request_type'], 'scopes' => array_values(array_unique($data['scope'])),
            'description' => $data['description'] ?? null, 'status' => $blockers === [] ? 'submitted' : 'verifying', 'blockers' => $blockers, 'idempotency_key_hash' => $hash,
        ]);
        if (in_array($data['request_type'], ['access', 'copy'], true)) {
            BuildPrivacyExport::dispatch($request->id);
        }

        return $request->fresh('resultFile');
    }

    /** @return array<string,mixed> */
    public function present(PrivacyRequest $request): array
    {
        $request->loadMissing('resultFile');

        return [
            'id' => $request->public_id, 'request_type' => $request->request_type, 'scope' => $request->scopes,
            'description' => $request->description, 'status' => $request->status, 'blockers' => $request->blockers ?? [],
            'result_file_id' => $request->resultFile?->public_id,
            'result_download_url' => $request->resultFile === null ? null : '/api/v1/files/'.$request->resultFile->public_id.'/content',
            'result_expires_at' => $request->result_expires_at?->toIso8601String(), 'requested_at' => $request->created_at?->toIso8601String(),
            'completed_at' => $request->completed_at?->toIso8601String(), 'resolution_note' => $request->resolution_note,
        ];
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, hash('sha256', (string) config('auth_tokens.identity_hash_secret')));
    }
}
