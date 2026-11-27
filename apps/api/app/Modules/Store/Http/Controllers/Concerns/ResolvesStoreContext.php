<?php

namespace App\Modules\Store\Http\Controllers\Concerns;

use App\Modules\Store\Models\Store;
use App\Modules\Store\Models\StoreMember;
use App\Modules\Store\Services\StoreCampusService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ResolvesStoreContext
{
    protected function currentStore(Request $request): Store
    {
        /** @var Store $store */
        $store = $request->attributes->get('store');

        return $store;
    }

    /** 当前操作的校区：默认主校区，可通过 campus_id 切换到其他开通校区。 */
    protected function currentCampusId(Request $request, bool $requireActive = true): int
    {
        return app(StoreCampusService::class)->resolve($this->currentStore($request), $request->input('campus_id'), $requireActive);
    }

    protected function currentMember(Request $request): StoreMember
    {
        /** @var StoreMember $member */
        $member = $request->attributes->get('store_member');

        return $member;
    }

    protected function assertCanManage(Request $request): void
    {
        if (! $this->currentMember($request)->canManage()) {
            throw ValidationException::withMessages(['member' => ['当前账号没有该操作权限，请联系店长处理。']]);
        }
    }

    protected function assertOwner(Request $request): void
    {
        $store = $this->currentStore($request);
        if ((int) $store->owner_user_id !== (int) $request->user()->id) {
            throw ValidationException::withMessages(['member' => ['只有店主可以执行该操作。']]);
        }
    }
}
