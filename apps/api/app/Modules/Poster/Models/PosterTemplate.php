<?php

namespace App\Modules\Poster\Models;

use App\Modules\Admin\Models\AdminUser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PosterTemplate extends Model
{
    public const CODE_COMMUNITY_POST = 'community_post';

    public const CODE_MATERIAL_SHARE = 'material_share';

    public const BACKGROUND_GRADIENT = 'gradient';

    public const BACKGROUND_IMAGE = 'image';

    public const BACKGROUND_SOLID = 'solid';

    public const DATE_RULE_RANGE = 'range';

    public const DATE_RULE_MONTHDAY = 'monthday';

    public const DATE_RULE_WEEKDAY = 'weekday';

    protected $fillable = [
        'public_id',
        'code',
        'name',
        'description',
        'canvas_width',
        'canvas_height',
        'background_type',
        'background_value',
        'banner_config',
        'body_config',
        'qrcode_config',
        'date_rule',
        'priority',
        'is_default',
        'is_active',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected $casts = [
        'canvas_width' => 'integer',
        'canvas_height' => 'integer',
        'background_value' => 'array',
        'banner_config' => 'array',
        'body_config' => 'array',
        'qrcode_config' => 'array',
        'date_rule' => 'array',
        'priority' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public static function boot(): void
    {
        parent::boot();
        self::creating(function (self $model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    public function updatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by_admin_id');
    }

    /**
     * 判断当前模板的日期规则是否覆盖给定日期。
     * 无规则（null）=> 始终匹配（兜底模板）。
     */
    public function matchesDate(\DateTimeInterface $date): bool
    {
        $rule = $this->date_rule;
        if ($rule === null || $rule === []) {
            return true;
        }
        $mode = $rule['mode'] ?? null;

        return match ($mode) {
            self::DATE_RULE_RANGE => $this->matchRange($rule, $date),
            self::DATE_RULE_MONTHDAY => $this->matchMonthday($rule, $date),
            self::DATE_RULE_WEEKDAY => $this->matchWeekday($rule, $date),
            default => true,
        };
    }

    private function matchRange(array $rule, \DateTimeInterface $date): bool
    {
        $target = $date instanceof CarbonInterface ? $date : new \DateTimeImmutable($date->format('Y-m-d'));
        foreach ($rule['ranges'] ?? [] as $range) {
            $start = CarbonImmutable::parse($range['start'] ?? null)?->startOfDay();
            $end = CarbonImmutable::parse($range['end'] ?? null)?->endOfDay();
            if ($start !== null && $end !== null && $target->between($start, $end)) {
                return true;
            }
        }

        return false;
    }

    private function matchMonthday(array $rule, \DateTimeInterface $date): bool
    {
        $current = $date->format('m-d');

        return in_array($current, $rule['monthdays'] ?? [], true);
    }

    private function matchWeekday(array $rule, \DateTimeInterface $date): bool
    {
        // ISO-8601: Monday=1 ... Sunday=7
        $current = (int) $date->format('N');

        return in_array($current, $rule['weekdays'] ?? [], true);
    }

    public function toPublicArray(): array
    {
        return [
            'public_id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'canvas_width' => $this->canvas_width,
            'canvas_height' => $this->canvas_height,
            'background_type' => $this->background_type,
            'background_value' => $this->background_value,
            'banner_config' => $this->banner_config,
            'body_config' => $this->body_config,
            'qrcode_config' => $this->qrcode_config,
        ];
    }
}
