<?php

namespace App\Modules\Operations\Services;

use App\Modules\Campus\Models\Campus;
use App\Modules\Operations\Models\CampusFeatureFlag;
use App\Modules\Operations\Support\ModuleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 模块生效判定的唯一口径：全局开关（disabled/enabled/percentage+audience）
 * 与校区覆盖（inherit/enabled/disabled）合并，并沿父子链逐级计算。
 * 小程序 capabilities 下发与后端 API 门禁都必须经本服务，保证所见即所判。
 */
class ModuleGateService
{
    private const CACHE_TTL_SECONDS = 60;

    private const CACHE_PREFIX = 'campus:module-overrides:';

    /**
     * 请求内 effective 结果 memo，key = code|campus|subject|platform。
     *
     * @var array<string, bool>
     */
    private array $effectiveMemo = [];

    /**
     * 请求内校区覆盖映射 memo。
     *
     * @var array<int, array<string, string>>
     */
    private array $overridesMemo = [];

    /**
     * 请求内校区 id => public_id memo。
     *
     * @var array<int, string|null>
     */
    private array $publicIdMemo = [];

    /**
     * 当前 memo 所属请求对象 id。Laravel Route 会缓存控制器实例（Octane/测试中同样跨请求存活），
     * 故请求级 memo 必须随请求对象切换自动清空，避免上一请求的判定值泄漏。
     */
    private ?int $memoRequestId = null;

    public function __construct(
        private readonly ReleaseControlService $releases,
        private readonly ModuleRegistry $registry,
    ) {}

    /**
     * 计算模块在指定校区/设备上下文下的最终生效状态。
     */
    public function effective(string $code, ?int $campusId = null, ?string $subject = null, ?string $platform = null): bool
    {
        if (! $this->registry->exists($code)) {
            // 未知模块一律 fail-close
            return false;
        }
        $this->syncRequestScope();
        $subject ??= 'anonymous';
        $memoKey = $code.'|'.($campusId ?? 'global').'|'.$subject.'|'.($platform ?? '-');
        if (array_key_exists($memoKey, $this->effectiveMemo)) {
            return $this->effectiveMemo[$memoKey];
        }

        $result = $this->compute($code, $campusId, $subject, $platform);
        $this->effectiveMemo[$memoKey] = $result;

        return $result;
    }

    /**
     * @return array<string, bool>
     */
    public function allEffective(?int $campusId = null, ?string $subject = null, ?string $platform = null): array
    {
        $map = [];
        foreach ($this->registry->codes() as $code) {
            $map[$code] = $this->effective($code, $campusId, $subject, $platform);
        }

        return $map;
    }

    /**
     * 从 HTTP 请求解析上下文（登录用户 campus_id 优先，匿名取 X-Campus-Id 解析值）并判定。
     */
    public function effectiveForRequest(Request $request, string $code): bool
    {
        return $this->effective(
            $code,
            $this->campusIdFromRequest($request),
            (string) ($request->header('X-Device-Id') ?: 'anonymous'),
            $request->header('X-Client-Platform'),
        );
    }

    public function campusIdFromRequest(Request $request): ?int
    {
        $user = $request->user();
        if ($user !== null && ! empty($user->campus_id)) {
            return (int) $user->campus_id;
        }
        $resolved = $request->attributes->get('campus_database_id');

        return $resolved !== null ? (int) $resolved : null;
    }

    /**
     * 实际判定校区的 public_id（登录用户校区可能与 X-Campus-Id 不同），供 capabilities 回显。
     */
    public function campusPublicIdFromRequest(Request $request): ?string
    {
        $campusId = $this->campusIdFromRequest($request);
        if ($campusId === null) {
            return null;
        }
        // X-Campus-Id 解析出的正是该校区时直接复用，避免查库
        if ((int) $request->attributes->get('campus_database_id') === $campusId) {
            return $request->attributes->get('campus_public_id');
        }
        $this->syncRequestScope();
        if (array_key_exists($campusId, $this->publicIdMemo)) {
            return $this->publicIdMemo[$campusId];
        }

        return $this->publicIdMemo[$campusId] = Campus::query()->whereKey($campusId)->value('public_id');
    }

    /**
     * 校区覆盖写入后调用，清除缓存与请求 memo。
     */
    public function forgetCampus(?int $campusId): void
    {
        if ($campusId === null) {
            return;
        }
        $this->syncRequestScope();
        Cache::forget(self::CACHE_PREFIX.$campusId);
        unset($this->overridesMemo[$campusId]);
        $this->effectiveMemo = [];
    }

    /**
     * 进入新请求时丢弃上一请求遗留的 memo（控制器实例可能跨请求复用）。
     */
    private function syncRequestScope(): void
    {
        $requestId = spl_object_id(app('request'));
        if ($this->memoRequestId !== $requestId) {
            $this->effectiveMemo = [];
            $this->overridesMemo = [];
            $this->publicIdMemo = [];
            $this->memoRequestId = $requestId;
        }
    }

    private function compute(string $code, ?int $campusId, string $subject, ?string $platform): bool
    {
        // 父链：任一祖先关闭，本模块必关（递归经 effective，带 memo）
        foreach ($this->registry->ancestors($code) as $ancestor) {
            if (! $this->effective($ancestor, $campusId, $subject, $platform)) {
                return false;
            }
        }

        return $this->selfEffective($code, $campusId, $subject, $platform);
    }

    private function selfEffective(string $code, ?int $campusId, string $subject, ?string $platform): bool
    {
        $global = $this->releases->evaluation($code, $subject, $platform);

        // 全局总闸门：disabled 或开关记录缺失时，校区覆盖不可开启
        if ($global['mode'] === null || $global['mode'] === 'disabled') {
            return false;
        }

        $override = $this->overrides($campusId)[$code] ?? 'inherit';

        return match ($override) {
            'enabled' => true,
            'disabled' => false,
            default => $global['enabled'], // inherit 或无记录跟随全局（含 percentage 灰度）
        };
    }

    /**
     * @return array<string, string> code => status(inherit/enabled/disabled)
     */
    private function overrides(?int $campusId): array
    {
        if ($campusId === null) {
            return [];
        }
        $this->syncRequestScope();
        if (array_key_exists($campusId, $this->overridesMemo)) {
            return $this->overridesMemo[$campusId];
        }

        $map = Cache::remember(
            self::CACHE_PREFIX.$campusId,
            self::CACHE_TTL_SECONDS,
            fn (): array => CampusFeatureFlag::query()
                ->where('campus_id', $campusId)
                ->pluck('status', 'code')
                ->all(),
        );

        return $this->overridesMemo[$campusId] = $map;
    }
}
