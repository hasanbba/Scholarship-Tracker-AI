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

        $verify = Permission::query()->updateOrCreate(
            ['name' => 'scholarships.verify'],
            ['label' => 'Verify scholarship versions'],
        );

        $publish = Permission::query()->updateOrCreate(
            ['name' => 'scholarships.publish'],
            ['label' => 'Publish scholarship cycle versions'],
        );

        $reviewPermissions = collect([
            'scholarships.review.view' => 'View data-quality review tasks',
            'scholarships.review.manage' => 'Assign and decide data-quality review tasks',
            'scholarships.changes.approve' => 'Approve reviewed scholarship changes and create versions',
            'scholarships.data-quality.manage' => 'Ingest and process source observations',
        ])->map(fn (string $label, string $name) => Permission::query()->updateOrCreate(['name' => $name], ['label' => $label]));

        $crawlerPermissions = collect([
            'crawler.workers.manage' => 'Provision and manage crawler workers',
            'crawler.sources.manage' => 'Configure registered source crawling',
            'crawler.jobs.view' => 'View crawler jobs',
            'crawler.jobs.manage' => 'Create and manage crawler jobs',
        ])->map(fn (string $label, string $name) => Permission::query()->updateOrCreate(['name' => $name], ['label' => $label]));

        $student->permissions()->sync([]);
        $admin->permissions()->sync([$adminAccess->id, $verify->id, $publish->id, ...$reviewPermissions->pluck('id')->all(), ...$crawlerPermissions->pluck('id')->all()]);
        $superAdmin->permissions()->sync([$adminAccess->id]);
    }
}
