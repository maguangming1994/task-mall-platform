<?php

namespace App\Modules\Store\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Store\Http\Controllers\Concerns\ResolvesStoreContext;
use App\Modules\Store\Models\StoreMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StoreManagerMemberController extends Controller
{
    use ResolvesStoreContext;

    public function index(Request $request): JsonResponse
    {
        $items = StoreMember::query()
            ->where('store_id', $this->currentStore($request)->id)
            ->with('user')
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get()
            ->map(fn (StoreMember $member): array => $this->present($member));

        return ApiResponse::success(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertOwner($request);
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'role' => ['sometimes', 'in:manager,staff'],
        ]);
        $user = User::query()->where('phone_hash', hash('sha256', $data['phone']))->orWhere('phone', $data['phone'])->first();
        if ($user === null) {
            throw ValidationException::withMessages(['phone' => ['未找到该手机号对应的平台用户，请先让对方注册。']]);
        }
        if ($user->status !== 'active') {
            throw ValidationException::withMessages(['phone' => ['该账号状态异常，无法加入门店。']]);
        }
        $member = StoreMember::query()->firstOrCreate(
            ['store_id' => $this->currentStore($request)->id, 'user_id' => $user->id],
            ['role' => $data['role'] ?? StoreMember::ROLE_STAFF, 'status' => 'active'],
        );
        if (! $member->wasRecentlyCreated) {
            $member->update(['status' => 'active', 'role' => $data['role'] ?? $member->role]);
        }

        return ApiResponse::success($this->present($member->fresh('user')), 201, '成员已加入门店。');
    }

    public function destroy(Request $request, StoreMember $member): JsonResponse
    {
        $this->assertOwner($request);
        abort_unless((int) $member->store_id === (int) $this->currentStore($request)->id, 404);
        if ($member->role === StoreMember::ROLE_OWNER) {
            throw ValidationException::withMessages(['member' => ['不能移除店主本人。']]);
        }
        $member->update(['status' => 'disabled']);

        return ApiResponse::success(null);
    }

    /** @return array<string, mixed> */
    private function present(StoreMember $member): array
    {
        return [
            'id' => $member->public_id,
            'role' => $member->role,
            'role_label' => match ($member->role) {
                StoreMember::ROLE_OWNER => '店主',
                StoreMember::ROLE_MANAGER => '店长',
                default => '店员',
            },
            'status' => $member->status,
            'nickname' => $member->user?->nickname,
            'avatar_url' => $member->user?->avatar_file_id,
            'phone_masked' => $this->maskPhone((string) $member->user?->phone),
            'joined_at' => $member->joined_at?->toIso8601String(),
        ];
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) >= 11 ? substr($phone, 0, 3).'****'.substr($phone, -4) : $phone;
    }
}
