<?php

namespace App\Modules\Poster\Services;

use App\Modules\Poster\Models\PosterTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PosterTemplateService
{
    private const CACHE_TTL = 120;

    /**
     * 根据 code 和当前日期解析出应使用的模板。
     * 优先匹配日期规则命中的模板（按 priority 降序），
     * 否则回退到 is_default=true 的默认模板。
     */
    public function resolve(string $code, ?\DateTimeInterface $date = null): ?PosterTemplate
    {
        $date = $date ?? Carbon::now();
        $cacheKey = 'poster-template:resolve:'.$code.':'.$date->format('Y-m-d');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($code, $date): ?PosterTemplate {
            $pool = PosterTemplate::query()
                ->where('code', $code)
                ->where('is_active', true)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get();

            // 先尝试日期规则命中的
            foreach ($pool as $template) {
                if ($template->date_rule !== null && $template->matchesDate($date)) {
                    return $template;
                }
            }

            // 再回退默认模板
            $default = $pool->first(fn (PosterTemplate $t): bool => $t->is_default);
            if ($default !== null) {
                return $default;
            }

            // 最后兜底：取第一条（即使没标记 default）
            return $pool->first();
        });
    }

    public function forgetResolved(string $code): void
    {
        Cache::forget('poster-template:resolve:'.$code.':'.Carbon::now()->format('Y-m-d'));
    }
}
