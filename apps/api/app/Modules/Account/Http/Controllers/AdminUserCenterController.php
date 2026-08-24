<?php

namespace App\Modules\Account\Http\Controllers;

use App\Common\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Services\PhoneVerificationService;
use App\Modules\Growth\Models\Referral;
use App\Modules\Identity\Services\IdentityVerificationSettingsService;
use App\Modules\Settings\Services\SystemSettingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminUserCenterController extends Controller
{
    public function overview(SystemSettingService $settings, IdentityVerificationSettingsService $identitySettings): JsonResponse
    {
        $today = CarbonImmutable::today();
        $taskReports = DB::table('task_reports')->where('status', 'submitted')->count();
        $contentReports = DB::table('content_reports')->where('status', 'submitted')->count();

        return ApiResponse::success([
            'summary' => [
                'new_users_today' => User::query()->whereDate('created_at', $today)->count(),
                'total_users' => User::query()->count(),
                'active_users' => User::query()->where('status', 'active')->count(),
                'phone_verified_users' => User::query()->whereNotNull('phone_verified_at')->count(),
                'profile_completed_users' => User::query()->whereNotNull('profile_completed_at')->count(),
                'task_claims_today' => DB::table('task_claims')->whereDate('created_at', $today)->count(),
                'paid_orders_today' => DB::table('orders')->whereDate('paid_at', $today)->count(),
                'withdrawn_today_cents' => (int) DB::table('wallet_withdrawals')->whereDate('completed_at', $today)->sum('received_amount_cents'),
            ],
            'pending' => [
                'low_task_reward_quota' => DB::table('task_reward_budgets')
                    ->whereRaw('(locked_amount - reserved_amount - issued_amount - released_amount) <= (unit_amount * 10)')
                    ->distinct()->count('task_id'),
                'task_reviews' => DB::table('task_submissions')->whereIn('status', ['submitted', 'reviewing'])->count(),
                'task_publish_approvals' => DB::table('task_publish_approvals')->where('status', 'pending')->count(),
                'withdrawals' => DB::table('wallet_withdrawals')->whereIn('status', ['submitted', 'reviewing'])->count(),
                'complaints' => $taskReports + $contentReports,
                'pending_fulfillment' => DB::table('orders')->where('status', 'pending_fulfillment')->count(),
                'after_sales' => DB::table('refunds')->whereIn('status', ['submitted', 'awaiting_return', 'return_in_transit', 'processing'])->count()
                    + DB::table('exchanges')->whereNotIn('status', ['completed', 'rejected', 'closed'])->count(),
                'incomplete_profiles' => User::query()->where('status', 'active')->whereNull('profile_completed_at')->count(),
            ],
            'platform' => [
                'total_task_claims' => DB::table('task_claims')->count(),
                'total_paid_orders' => DB::table('orders')->whereNotNull('paid_at')->count(),
                'total_referrals' => DB::table('referrals')->count(),
                'rewarded_referrals' => DB::table('referrals')->whereNotNull('rewarded_at')->count(),
                'total_withdrawn_cents' => (int) DB::table('wallet_withdrawals')->whereNotNull('completed_at')->sum('received_amount_cents'),
            ],
            'policies' => [
                'self_registration_enabled' => $this->setting($settings, 'accounts.self_registration_enabled', true),
                'growth_levels_enabled' => $this->setting($settings, 'accounts.growth_levels_enabled', true),
                'referral_milestones' => $this->setting($settings, 'tasks.referral_completion_milestones', ['first_task', 'first_cash_order']),
                'referral_monthly_cap' => $this->setting($settings, 'tasks.referral_monthly_cap', 5),
                'referral_reward_points' => (int) ($this->setting($settings, 'points.source_reward_amounts', ['referral' => 0])['referral'] ?? 0),
                'real_name_collection_enabled' => $identitySettings->effective()['collection_enabled'],
            ],
            'level_rules' => $this->levelRules($settings),
            'trend' => $this->sevenDayTrend(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function users(Request $request, PhoneVerificationService $phones, SystemSettingService $settings): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'in:active,disabled,cancelled,merged'],
            'verification' => ['sometimes', 'in:verified,partial,unverified'],
            'referral' => ['sometimes', 'in:inviter,referred,none'],
            'started_at' => ['sometimes', 'date'],
            'ended_at' => ['sometimes', 'date', 'after_or_equal:started_at'],
            'page_size' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);

        $query = User::query()
            ->with(['assetAccount', 'identities'])
            ->select('users.*')
            ->selectSub(fn ($sub) => $sub->from('task_claims')->selectRaw('COUNT(*)')->whereColumn('task_claims.user_id', 'users.id'), 'task_claim_count')
            ->selectSub(fn ($sub) => $sub->from('task_claims')->selectRaw('COUNT(*)')->whereColumn('task_claims.user_id', 'users.id')->where('task_claims.status', 'approved'), 'approved_task_count')
            ->selectSub(fn ($sub) => $sub->from('orders')->selectRaw('COUNT(*)')->whereColumn('orders.user_id', 'users.id')->whereNotNull('orders.completed_at')->whereRaw('(orders.paid_wallet_amount + orders.paid_external_amount) > 0'), 'completed_cash_order_count')
            ->selectSub(fn ($sub) => $sub->from('referrals')->selectRaw('COUNT(*)')->whereColumn('referrals.referrer_user_id', 'users.id'), 'referral_count')
            ->selectSub(fn ($sub) => $sub->from('user_identities')->selectRaw('MAX(last_login_at)')->whereColumn('user_identities.user_id', 'users.id'), 'last_login_at');

        $this->applyUserFilters($query, $filters, $phones);
        $paginator = $query->latest('users.id')->paginate((int) ($filters['page_size'] ?? 20));
        $rules = $this->levelRules($settings);
        $items = collect($paginator->items())->map(fn (User $user) => $this->presentUser($user, $rules));

        return ApiResponse::success([
            'items' => $items,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'privacy' => [
                'phone_masked_by_default' => true,
                'bulk_export_enabled' => false,
                'sensitive_access_requires_recent_verification' => true,
            ],
        ]);
    }

    public function referrals(Request $request, SystemSettingService $settings): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'max:30'],
            'page_size' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);
        $query = Referral::query()->with(['referrer', 'referred']);
        if (($filters['keyword'] ?? '') !== '') {
            $keyword = trim($filters['keyword']);
            $query->where(function ($sub) use ($keyword): void {
                $sub->where('public_id', 'like', "%{$keyword}%")
                    ->orWhereHas('referrer', fn ($users) => $users->where('public_id', 'like', "%{$keyword}%")->orWhere('nickname', 'like', "%{$keyword}%"))
                    ->orWhereHas('referred', fn ($users) => $users->where('public_id', 'like', "%{$keyword}%")->orWhere('nickname', 'like', "%{$keyword}%"));
            });
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        $paginator = $query->latest('id')->paginate((int) ($filters['page_size'] ?? 20));
        $reward = (int) ($this->setting($settings, 'points.source_reward_amounts', ['referral' => 0])['referral'] ?? 0);
        $items = collect($paginator->items())->map(fn (Referral $referral) => [
            'id' => $referral->public_id,
            'inviter' => $this->presentReferralUser($referral->referrer),
            'referred_user' => $this->presentReferralUser($referral->referred),
            'status' => $referral->status,
            'first_task_completed' => $referral->first_task_completed_at !== null,
            'first_cash_order_completed' => $referral->first_cash_order_completed_at !== null,
            'reward_points' => $referral->rewarded_at === null ? 0 : $reward,
            'bound_at' => $referral->bound_at?->toIso8601String(),
            'rewarded_at' => $referral->rewarded_at?->toIso8601String(),
        ]);

        return ApiResponse::success([
            'items' => $items,
            'pagination' => ['page' => $paginator->currentPage(), 'page_size' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ]);
    }

    public function verifications(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:verified,partial,unverified'],
            'page_size' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);
        $query = User::query()->with('identities')->latest('id');
        $this->applyVerificationFilter($query, $filters['status'] ?? null);
        $paginator = $query->paginate((int) ($filters['page_size'] ?? 20));
        $items = collect($paginator->items())->map(function (User $user): array {
            $phone = $user->phone_verified_at !== null;
            $profile = $user->profile_completed_at !== null;
            $wechat = $user->identities->contains(fn ($identity) => in_array($identity->provider, ['wechat_miniprogram', 'wechat_official_account'], true));

            return [
                'user' => ['id' => $user->public_id, 'nickname' => $user->nickname, 'phone_masked' => $this->maskPhone($user->phone)],
                'phone_verified' => $phone,
                'profile_completed' => $profile,
                'wechat_bound' => $wechat,
                'status' => $phone && $profile ? 'verified' : ($phone || $profile || $wechat ? 'partial' : 'unverified'),
                'real_name_status' => 'not_collected',
                'updated_at' => $user->updated_at?->toIso8601String(),
            ];
        });

        return ApiResponse::success([
            'items' => $items,
            'pagination' => ['page' => $paginator->currentPage(), 'page_size' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
            'identity_policy' => [
                'collect_id_number' => false,
                'collect_id_images' => false,
                'note' => '当前仅核验手机号、资料完整度和微信身份绑定；如提现通道要求实名，应接入持牌认证服务并仅保存认证结果与必要凭证摘要。',
            ],
        ]);
    }

    private function applyUserFilters(Builder $query, array $filters, PhoneVerificationService $phones): void
    {
        if (($filters['keyword'] ?? '') !== '') {
            $keyword = trim($filters['keyword']);
            $query->where(function (Builder $sub) use ($keyword, $phones): void {
                $sub->where('public_id', 'like', "%{$keyword}%")->orWhere('nickname', 'like', "%{$keyword}%");
                if (preg_match('/^\+?[0-9]{6,20}$/', $keyword)) {
                    $phone = str_starts_with($keyword, '+86') ? substr($keyword, 3) : $keyword;
                    $sub->orWhere('phone_hash', $phones->phoneHash($phone));
                }
            });
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        $this->applyVerificationFilter($query, $filters['verification'] ?? null);
        if (($filters['referral'] ?? '') === 'inviter') {
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('referrals')->whereColumn('referrals.referrer_user_id', 'users.id'));
        } elseif (($filters['referral'] ?? '') === 'referred') {
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('referrals')->whereColumn('referrals.referred_user_id', 'users.id'));
        } elseif (($filters['referral'] ?? '') === 'none') {
            $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('referrals')->whereColumn('referrals.referred_user_id', 'users.id'));
        }
        if (($filters['started_at'] ?? '') !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['started_at'])->startOfDay());
        }
        if (($filters['ended_at'] ?? '') !== '') {
            $query->where('created_at', '<=', CarbonImmutable::parse($filters['ended_at'])->endOfDay());
        }
    }

    private function applyVerificationFilter(Builder $query, ?string $status): void
    {
        if ($status === 'verified') {
            $query->whereNotNull('phone_verified_at')->whereNotNull('profile_completed_at');
        } elseif ($status === 'partial') {
            $query->whereNotNull('phone_verified_at')->whereNull('profile_completed_at');
        } elseif ($status === 'unverified') {
            $query->whereNull('phone_verified_at')->whereNull('profile_completed_at');
        }
    }

    /** @param array<int,array<string,mixed>> $rules */
    private function presentUser(User $user, array $rules): array
    {
        $approved = (int) $user->getAttribute('approved_task_count');
        $cashOrders = (int) $user->getAttribute('completed_cash_order_count');
        $level = collect($rules)->reverse()->first(fn (array $rule) => $approved >= $rule['approved_tasks'] && $cashOrders >= $rule['cash_orders'] && (! $rule['profile_required'] || $user->profile_completed_at !== null)) ?? $rules[0];
        $asset = $user->assetAccount;

        return [
            'id' => $user->public_id,
            'nickname' => $user->nickname,
            'phone_masked' => $this->maskPhone($user->phone),
            'status' => $user->status,
            'level' => ['code' => $level['code'], 'name' => $level['name']],
            'assets' => [
                'points_available' => $asset?->points_available ?? 0,
                'points_frozen' => $asset?->points_frozen ?? 0,
                'wallet_available_cents' => $asset?->wallet_available_cents ?? 0,
                'wallet_frozen_cents' => $asset?->wallet_frozen_cents ?? 0,
                'coupons_available' => $asset?->coupon_available ?? 0,
            ],
            'task_metrics' => ['claimed' => (int) $user->getAttribute('task_claim_count'), 'approved' => $approved],
            'cash_order_count' => $cashOrders,
            'referral_count' => (int) $user->getAttribute('referral_count'),
            'verification' => [
                'phone' => $user->phone_verified_at !== null,
                'profile' => $user->profile_completed_at !== null,
                'wechat' => $user->identities->contains(fn ($identity) => in_array($identity->provider, ['wechat_miniprogram', 'wechat_official_account'], true)),
            ],
            'created_at' => $user->created_at?->toIso8601String(),
            'last_login_at' => $user->getAttribute('last_login_at'),
        ];
    }

    private function presentReferralUser(?User $user): array
    {
        return $user === null
            ? ['id' => null, 'nickname' => '已注销用户', 'phone_masked' => null]
            : ['id' => $user->public_id, 'nickname' => $user->nickname, 'phone_masked' => $this->maskPhone($user->phone)];
    }

    /** @return array<int,array<string,mixed>> */
    private function levelRules(SystemSettingService $settings): array
    {
        return [
            ['code' => 'new', 'name' => '新用户', 'approved_tasks' => 0, 'cash_orders' => 0, 'profile_required' => false, 'benefits' => ['基础任务与商城权限']],
            ['code' => 'growing', 'name' => '成长用户', 'approved_tasks' => 0, 'cash_orders' => 0, 'profile_required' => true, 'benefits' => ['资料完善标识', '成长任务推荐']],
            ['code' => 'active', 'name' => '活跃用户', 'approved_tasks' => (int) $this->setting($settings, 'accounts.growth_active_task_count', 1), 'cash_orders' => (int) $this->setting($settings, 'accounts.growth_active_cash_order_count', 1), 'profile_required' => true, 'benefits' => ['优先展示适配任务', '活动参与资格']],
            ['code' => 'core', 'name' => '核心用户', 'approved_tasks' => (int) $this->setting($settings, 'accounts.growth_core_task_count', 5), 'cash_orders' => (int) $this->setting($settings, 'accounts.growth_core_cash_order_count', 3), 'profile_required' => true, 'benefits' => ['高质量用户标识', '专属活动资格']],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function sevenDayTrend(): array
    {
        return collect(range(6, 0))->map(function (int $offset): array {
            $date = CarbonImmutable::today()->subDays($offset);

            return [
                'date' => $date->format('m-d'),
                'new_users' => User::query()->whereDate('created_at', $date)->count(),
                'task_claims' => DB::table('task_claims')->whereDate('created_at', $date)->count(),
                'paid_orders' => DB::table('orders')->whereDate('paid_at', $date)->count(),
            ];
        })->all();
    }

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        return preg_match('/^(\d{3})\d+(\d{4})$/', $phone, $matches) ? $matches[1].'****'.$matches[2] : '***';
    }

    private function setting(SystemSettingService $settings, string $key, mixed $fallback): mixed
    {
        try {
            return $settings->read($key)['value'];
        } catch (Throwable) {
            return $fallback;
        }
    }
}
