<?php

namespace App\Modules\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * 短链映射表。
 *
 * 短链码为 6 位随机字母数字（去掉易混字符 0/O/1/l/I），
 * 不暴露任何底层自增 ID，防止被枚举遍历。
 */
class ShortLink extends Model
{
    public const CODE_LENGTH = 6;

    /** 去掉易混字符的字母数字集：约 568 万种组合。 */
    public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    protected $fillable = ['code', 'target_type', 'target_id', 'clicks', 'last_clicked_at'];

    /**
     * 获取或创建某目标的短链，同目标始终返回同一短链码。
     */
    public static function ensureFor(string $targetType, int $targetId): self
    {
        $existing = self::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // 不用外层事务包裹重试：PostgreSQL 中事务内唯一键冲突会中止事务，
        // 之后的查询会失败，因此每次插入使用自动提交，冲突后再查重。
        // 最多重试 5 次，理论上 6 位码 568 万种，冲突概率极低
        for ($i = 0; $i < 5; $i++) {
            $code = self::randomCode();
            try {
                return self::query()->create([
                    'code' => $code,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'clicks' => 0,
                ]);
            } catch (UniqueConstraintViolationException) {
                // 短链码被占用，或并发请求已为同一目标创建了短链。
                $existing = self::query()
                    ->where('target_type', $targetType)
                    ->where('target_id', $targetId)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
        }

        // 5 次都撞了，直接抛异常——实际上不太可能
        throw new \RuntimeException('生成短链码反复冲突');
    }

    /**
     * 6 位随机字母数字。
     */
    public static function randomCode(): string
    {
        $alphabet = self::ALPHABET;
        $len = strlen($alphabet);
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $len - 1)];
        }

        return $code;
    }

    /**
     * 点击 +1（原子自增，避免并发访问丢失计数）。
     */
    public function recordClick(): void
    {
        $this->increment('clicks', 1, ['last_clicked_at' => now()]);
    }
}
