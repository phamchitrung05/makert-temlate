<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InitialSchemaTest extends TestCase
{
    public function test_initial_schema_migrates_and_rolls_back(): void
    {
        config()->set('database.connections.schema_check', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        Artisan::call('migrate', ['--database' => 'schema_check', '--force' => true]);

        $schema = Schema::connection('schema_check');

        foreach ([
            'users',
            'customers',
            'customer_identities',
            'permissions',
            'roles',
            'model_has_permissions',
            'model_has_roles',
            'role_has_permissions',
            'media',
            'activity_log',
            'personal_access_tokens',
            'slugable',
            'resources',
            'categories',
            'tags',
            'technologies',
            'categorizables',
            'taggables',
            'resource_technology',
            'resource_versions',
            'downloads',
        ] as $table) {
            $this->assertTrue($schema->hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue($schema->hasColumn('downloads', 'customer_id'));
        $this->assertFalse($schema->hasColumn('downloads', 'entitlement_id'));
        $this->assertFalse($schema->hasColumn('resources', 'slug'));

        Artisan::call('migrate:reset', ['--database' => 'schema_check', '--force' => true]);

        $this->assertFalse($schema->hasTable('downloads'));
        $this->assertFalse($schema->hasTable('media'));
        $this->assertFalse($schema->hasTable('users'));

        DB::purge('schema_check');
    }
}
