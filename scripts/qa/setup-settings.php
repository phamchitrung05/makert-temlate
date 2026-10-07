<?php

/** Creates disposable browser QA data under .zcode; refuses to replace an existing database. */
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$root = realpath(__DIR__.'/../..');
$qa = $root.'/.zcode/fix1-settings-qa';
if (! is_dir($qa)) {
    mkdir($qa, 0755, true);
}
$database = $qa.'/database.sqlite';
if (file_exists($database)) {
    throw new RuntimeException('QA database đã tồn tại; không ghi đè fixture hoặc database đang dùng.');
}
touch($database);
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$database);
putenv('CACHE_STORE=array');
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database, 'cache.default' => 'array', 'permission.cache.store' => 'array']);
Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
Artisan::call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);
foreach (['admin', 'viewer', 'denied'] as $kind) {
    $actor = User::factory()->create(['name' => 'QA '.$kind, 'email' => 'qa-'.$kind.'@example.test', 'password' => Hash::make('Fix1-QA-Only-2026!'), 'status' => 'active']);
    if ($kind === 'admin') {
        $actor->assignRole('admin');
    } elseif ($kind === 'viewer') {
        $actor->givePermissionTo('settings.view');
    }
}
foreach (['logo' => [320, 80], 'favicon' => [64, 64], 'invalid-icon' => [64, 32]] as $kind => [$width, $height]) {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 75, 65, 180));
    imagestring($image, 5, 8, 8, 'QA', imagecolorallocate($image, 255, 255, 255));
    imagepng($image, $qa.'/'.$kind.'.png');
    imagedestroy($image);
}
file_put_contents($qa.'/scenario.json', json_encode(['mode' => 'normal']));
echo json_encode(['database' => $database, 'accounts' => ['qa-admin@example.test', 'qa-viewer@example.test', 'qa-denied@example.test'], 'password' => 'Fix1-QA-Only-2026!', 'files' => $qa], JSON_UNESCAPED_SLASHES).PHP_EOL;
