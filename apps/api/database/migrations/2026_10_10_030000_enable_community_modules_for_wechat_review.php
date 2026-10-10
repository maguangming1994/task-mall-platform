<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 微信代码审核整改：全局开启校园社区浏览与发帖模块。
 *
 * 背景：上一轮提审为规避社交类目，在发布管控中人工关闭了
 * module_community / module_community_post（游客与无校区上下文走全局开关，
 * 关闭即 fail-close，审核员点击社区 Tab 只能看到「功能升级中」占位页）。
 * 本次审核要求补选「社交-笔记」类目并开放完整社区流程，故随发布重新全局开启。
 *
 * 不写 down()：这是运营层面的开关决策，回滚旧版本不应强制重新关闭线上模块。
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $codes = ['module_community', 'module_community_post'];

    private string $reason = '配合微信代码审核整改，全局开放校园社区浏览与发帖完整流程（补选社交-笔记类目）。';

    public function up(): void
    {
        // feature_flag_versions.created_by_admin_id 为非空外键：迁移无人值守，
        // 取首位超级管理员作为系统操作代行人；无超管时仅更新开关主表（功能即时生效），
        // 版本快照留待管理员下次在后台变更时补齐。
        $actorId = DB::table('admin_users')
            ->join('admin_user_role', 'admin_users.id', '=', 'admin_user_role.admin_user_id')
            ->join('roles', 'roles.id', '=', 'admin_user_role.role_id')
            ->where('admin_users.status', 'active')
            ->where('roles.code', 'super_admin')
            ->min('admin_users.id');

        foreach ($this->codes as $code) {
            $flag = DB::table('feature_flags')->where('code', $code)->first();
            if (! $flag) {
                Log::warning('社区模块开启迁移跳过：开关记录不存在。', ['code' => $code]);

                continue;
            }
            if ($flag->mode === 'enabled' && (int) $flag->rollout_percentage === 100) {
                continue;
            }

            $nextVersion = (int) $flag->version + 1;
            DB::table('feature_flags')->where('id', $flag->id)->update([
                'mode' => 'enabled',
                'rollout_percentage' => 100,
                'audience' => null,
                'version' => $nextVersion,
                'updated_by_admin_id' => $actorId,
                'updated_at' => now(),
            ]);

            if ($actorId) {
                DB::table('feature_flag_versions')->insert([
                    'feature_flag_id' => $flag->id,
                    'version' => $nextVersion,
                    'mode' => 'enabled',
                    'rollout_percentage' => 100,
                    'audience' => null,
                    'reason' => $this->reason,
                    'created_by_admin_id' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // 运营开关决策不随迁移回滚。
    }
};
