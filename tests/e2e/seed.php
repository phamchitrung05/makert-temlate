<?php

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Seed admin và 12 Facebook draft trong DB E2E tạm.
 * CÁC HÀM/METHOD TRONG FILE: Không có; migration/fixture entrypoint.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): SQLite rỗng riêng -> fixture; không chạy worker/provider.
 * =====================================================================
 */

use App\Models\User;
use App\Services\Marketing\SocialPostLifecycleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

$app = require __DIR__.'/bootstrap.php';
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);
$actor = User::factory()->create([
    'name' => 'Browser Test Admin', 'username' => 'browser-admin',
    'email' => 'browser-admin@example.test', 'status' => 'active',
    'password' => Hash::make('e2e-password'),
]);
$actor->assignRole('admin');
foreach (range(1, 12) as $number) {
    app(SocialPostLifecycleService::class)->create(['caption' => sprintf('E2E Facebook %02d', $number)], $actor->id);
}
echo 'E2E fixtures ready.'.PHP_EOL;
