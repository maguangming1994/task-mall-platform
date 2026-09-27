<?php

namespace App\Modules\Task\Services;

use App\Models\User;
use App\Modules\Admin\Models\AdminSession;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Task\Models\TaskPublisher;
use App\Modules\Task\Models\TaskPublisherSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaskPublishingPermissionService
{
    public function update(AdminUser $actor, User $user, bool $granted): User
    {
        return DB::transaction(function () use ($actor, $user, $granted): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($granted) {
                if ($locked->status !== 'active') {
                    throw ValidationException::withMessages(['granted' => ['只能向正常状态的用户授予任务发布权限。']]);
                }
                $publisher = $this->ensurePublisher($locked);
                $publisher->update([
                    'verification_status' => 'verified',
                    'publisher_level' => 'user',
                    'status' => 'active',
                ]);
                $locked->update([
                    'can_publish_tasks' => true,
                    'task_publish_permission_granted_at' => now(),
                    'task_publish_permission_granted_by_admin_id' => $actor->id,
                ]);
            } else {
                $locked->update([
                    'can_publish_tasks' => false,
                    'task_publish_permission_granted_at' => null,
                    'task_publish_permission_granted_by_admin_id' => null,
                ]);
                $publisher = TaskPublisher::query()
                    ->where('publisher_type', 'user')
                    ->where('linked_user_id', $locked->id)
                    ->lockForUpdate()
                    ->first();
                if ($publisher !== null) {
                    $publisher->update(['status' => 'suspended']);
                    TaskPublisherSession::query()
                        ->where('task_publisher_id', $publisher->id)
                        ->whereNull('revoked_at')
                        ->update(['revoked_at' => now(), 'updated_at' => now()]);
                }
            }

            return $locked->fresh(['taskPublisher', 'taskPublishPermissionGrantedBy']);
        });
    }

    public function userCanPublish(User $user): bool
    {
        return User::query()
            ->whereKey($user->id)
            ->where('status', 'active')
            ->where('can_publish_tasks', true)
            ->exists();
    }

    public function publisherCanPublish(TaskPublisher $publisher): bool
    {
        if ($publisher->status !== 'active') {
            return false;
        }

        if ($publisher->publisher_type === 'platform_admin') {
            $admin = AdminUser::query()->find($publisher->admin_user_id);

            return $admin !== null
                && ! $admin->is_service_account
                && $admin->status === 'active'
                && $admin->roles()->where('code', 'platform_admin')->exists()
                && $admin->hasPermission('task.frontend_publish');
        }

        if ($publisher->publisher_type !== 'user' || $publisher->linked_user_id === null) {
            return false;
        }

        $proxyIsValid = AdminUser::query()
            ->whereKey($publisher->admin_user_id)
            ->where('status', 'active')
            ->where('is_service_account', true)
            ->exists();
        $userIsAuthorized = User::query()
            ->whereKey($publisher->linked_user_id)
            ->where('status', 'active')
            ->where('can_publish_tasks', true)
            ->exists();

        return $proxyIsValid && $userIsAuthorized;
    }

    /** @return array<string, mixed> */
    public function present(User $user): array
    {
        $user->loadMissing('taskPublisher');
        $publisher = $user->taskPublisher;
        $granted = $user->status === 'active' && $user->can_publish_tasks;

        return [
            'granted' => $granted,
            'status' => $granted ? 'granted' : ($publisher === null ? null : 'revoked'),
            'publisher_id' => $publisher?->public_id,
            'updated_at' => ($granted ? $user->task_publish_permission_granted_at : $publisher?->updated_at)?->toIso8601String(),
        ];
    }

    private function ensurePublisher(User $user): TaskPublisher
    {
        $publisher = TaskPublisher::query()
            ->where('publisher_type', 'user')
            ->where('linked_user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($publisher === null) {
            $proxy = AdminUser::query()->create([
                'account' => 'publisher-user-'.strtolower($user->public_id),
                'display_name' => $this->proxyDisplayName($user),
                'password' => bin2hex(random_bytes(32)),
                'status' => 'active',
                'is_service_account' => true,
                'requires_mfa' => false,
            ]);

            return TaskPublisher::query()->create([
                'publisher_type' => 'user',
                'admin_user_id' => $proxy->id,
                'linked_user_id' => $user->id,
                'verification_status' => 'verified',
                'publisher_level' => 'user',
                'status' => 'active',
            ]);
        }

        $proxy = AdminUser::query()->find($publisher->admin_user_id);
        if ($proxy === null || ! $proxy->is_service_account) {
            throw ValidationException::withMessages(['granted' => ['该用户的发布主体配置异常，请联系系统管理员。']]);
        }
        $proxy->update(['display_name' => $this->proxyDisplayName($user), 'status' => 'active']);
        $proxy->roles()->detach();
        AdminSession::query()
            ->where('admin_user_id', $proxy->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoke_reason' => 'service_account', 'updated_at' => now()]);

        return $publisher;
    }

    private function proxyDisplayName(User $user): string
    {
        return Str::limit($user->nickname.'（任务发布主体）', 80, '');
    }
}
