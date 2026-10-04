<?php

namespace App\Modules\Wallet\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\UserIdentityVerification;
use App\Modules\Wallet\Models\UserBankCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserBankCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = UserBankCard::query()->where('user_id', $request->user()->id)
            ->orderByDesc('is_default')->latest('id')->get();

        return ApiResponse::success(['items' => $items->map(fn (UserBankCard $c) => $this->present($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cardholder_name' => ['required', 'string', 'min:2', 'max:40'],
            'bank_name' => ['required', 'string', 'min:2', 'max:40'],
            'card_number' => ['required', 'string', 'regex:/^\d{12,19}$/'],
            'phone' => ['required', 'string', 'regex:/^1\d{10}$/'],
        ]);
        $user = $request->user();
        $cardTail = substr($data['card_number'], -4);

        // 持卡人须与实名认证姓名一致（实名库仅留存姓氏脱敏，至少保证姓氏一致）
        $verified = UserIdentityVerification::query()
            ->where('user_id', $user->id)->where('status', 'verified')->latest('id')->first();
        if ($verified !== null) {
            $verifiedSurname = mb_substr((string) $verified->real_name_masked, 0, 1);
            if ($verifiedSurname !== '' && mb_substr($data['cardholder_name'], 0, 1) !== $verifiedSurname) {
                throw ValidationException::withMessages([
                    'cardholder_name' => ['持卡人姓名需与实名认证姓名保持一致。'],
                ]);
            }
        }

        // 不存完整卡号，同银行 + 尾号视为同一张卡，避免重复绑定
        $duplicate = UserBankCard::query()
            ->where('user_id', $user->id)
            ->where('bank_name', $data['bank_name'])
            ->where('card_tail', $cardTail)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['card_number' => ['该银行卡已绑定，请勿重复添加。']]);
        }

        $card = DB::transaction(function () use ($user, $data, $cardTail) {
            $isFirst = ! UserBankCard::query()->where('user_id', $user->id)->exists();

            return UserBankCard::create([
                'user_id' => $user->id,
                'cardholder_name' => $data['cardholder_name'],
                'bank_name' => $data['bank_name'],
                'card_tail' => $cardTail,
                'phone_masked' => substr($data['phone'], 0, 3).'****'.substr($data['phone'], -4),
                'is_default' => $isFirst,
            ]);
        });

        return ApiResponse::success($this->present($card), 201);
    }

    public function setDefault(Request $request, UserBankCard $userBankCard): JsonResponse
    {
        abort_unless($userBankCard->user_id === $request->user()->id, 403);

        DB::transaction(function () use ($request, $userBankCard) {
            UserBankCard::query()->where('user_id', $request->user()->id)->update(['is_default' => false]);
            $userBankCard->update(['is_default' => true]);
        });

        return ApiResponse::success($this->present($userBankCard->fresh()));
    }

    public function destroy(Request $request, UserBankCard $userBankCard): JsonResponse
    {
        abort_unless($userBankCard->user_id === $request->user()->id, 403);
        $userBankCard->delete();

        return ApiResponse::success(['id' => $userBankCard->public_id, 'deleted' => true]);
    }

    private function present(UserBankCard $card): array
    {
        return [
            'id' => $card->public_id,
            'cardholder_name' => $card->cardholder_name,
            'bank_name' => $card->bank_name,
            'card_tail' => $card->card_tail,
            'card_masked' => '**** **** **** '.$card->card_tail,
            'phone_masked' => $card->phone_masked,
            'is_default' => (bool) $card->is_default,
            'created_at' => $card->created_at?->toIso8601String(),
        ];
    }
}
