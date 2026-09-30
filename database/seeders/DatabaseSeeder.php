<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $student = Role::query()->updateOrCreate(['name' => 'student'], ['label' => 'Student']);
        $admin = Role::query()->updateOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $superAdmin = Role::query()->updateOrCreate(['name' => 'super_admin'], ['label' => 'Super Administrator']);

        $adminAccess = Permission::query()->updateOrCreate(
            ['name' => 'admin.access'],
            ['label' => 'Access the administrative foundation'],
        );

        $student->permissions()->sync([]);
        $admin->permissions()->sync([$adminAccess->id]);
        $superAdmin->permissions()->sync([$adminAccess->id]);
    }
}
