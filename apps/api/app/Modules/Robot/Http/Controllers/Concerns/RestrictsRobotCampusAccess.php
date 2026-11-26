<?php

namespace App\Modules\Robot\Http\Controllers\Concerns;

use App\Modules\Admin\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * 机器人模块的校区代理限制：学校代理只能看到、只能配置自己绑定学校的机器人数据。
 */
trait RestrictsRobotCampusAccess
{
    protected function isCampusAgent(Request $request): bool
    {
        $admin = $request->user();
        if (! $admin instanceof AdminUser) {
            return false;
        }
        if ($admin->roles()->where('code', 'super_admin')->exists()) {
            return false;
        }

        return $admin->roles()->where('code', 'campus_agent')->exists();
    }

    /** @return Collection<int, int> */
    protected function agentCampusIds(Request $request): Collection
    {
        $admin = $request->user();

        return $admin instanceof AdminUser
            ? $admin->campuses()->pluck('campuses.id')
            : collect();
    }
}
