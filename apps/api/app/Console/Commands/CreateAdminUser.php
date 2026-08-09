<?php

namespace App\Console\Commands;

use App\Modules\Admin\Models\AdminUser;
use App\Modules\Admin\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create
        {account : Unique login account}
        {--name= : Display name}
        {--email= : Contact email}
        {--role=platform_admin : Initial role code}
        {--password= : Password; omit to enter it securely}';

    protected $description = 'Create an administrator without using a shared default password';

    public function handle(): int
    {
        $account = mb_strtolower(trim((string) $this->argument('account')));
        $password = $this->option('password') ?: $this->secret('Password (at least 12 characters, letters and numbers)');
        $role = Role::query()->where('code', (string) $this->option('role'))->first();

        if ($role === null) {
            $this->error('The specified role does not exist. Run the RBAC seeder first.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'account' => $account,
            'display_name' => $this->option('name') ?: $account,
            'email' => $this->option('email'),
            'password' => $password,
        ], [
            'account' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[a-z0-9._-]+$/', 'unique:admin_users,account'],
            'display_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:191', 'unique:admin_users,email'],
            'password' => ['required', 'string', 'min:12', 'max:200', 'regex:/[A-Za-z]/', 'regex:/[0-9]/'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $data = $validator->validated();
        $admin = AdminUser::query()->create([
            'account' => $data['account'],
            'display_name' => $data['display_name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => 'active',
        ]);
        $admin->roles()->attach($role->id);

        $this->info("Administrator {$admin->account} created with role {$role->code}.");

        return self::SUCCESS;
    }
}
