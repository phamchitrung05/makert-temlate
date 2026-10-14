<?php

namespace Tests\Fixtures\ResourceTemplate;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use ZipArchive;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đóng băng fixture TPL-00 từ hai nguồn template đã chọn
 * =====================================================================
 * Helper test tạo ZIP normalized dùng slash, sao chép nguyên bytes ZIP Stitch
 * original và ghi expected manifest/checksum. Chỉ chạy thủ công khi muốn đổi
 * baseline; test không tự rebuild expected output từ input đang kiểm tra.
 * CÁC HÀM/METHOD TRONG FILE:
 * - build(): tạo snapshot ZIP, expected input/output và placeholder synthetic
 * - writeZip(): ghi archive fixture mà không thực thi HTML/JS
 * - writeJson(): ghi JSON UTF-8 có định dạng để review
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT: root repository, normalized folder và original ZIP có sẵn
 * - OUTPUT: file dưới tests/Fixtures/ResourceTemplate; không sửa file trong template/
 * - SIDE EFFECT: Tạo/thay baseline fixture ở đường dẫn cố định trong repository
 * - EXCEPTION/TRANSACTION: RuntimeException/JSON exception khi input hoặc write lỗi; không DB
 * =====================================================================
 */
final class FixtureBuilder
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo hai snapshot độc lập và expected contract v1
     * =====================================================================
     * INPUT: Absolute root; normalized folder và .original.zip hoặc raw snapshot đã giữ.
     * OUTPUT: ZIP fixture, template.json expected, inventory/hash và 5 PNG giả.
     * SIDE EFFECT: Chỉ ghi tests/Fixtures/ResourceTemplate, không extract/chạy package.
     * EXCEPTION/TRANSACTION: Ném lỗi khi input/write không hợp lệ; không mở transaction.
     * =====================================================================
     */
    public function build(string $root): void
    {
        $files = new Filesystem;
        $sourceRoot = $root.'/template/stitch_full_page_ui_design';
        $original = $root.'/template/stitch_full_page_ui_design.original.zip';
        if (! $files->isFile($original)) {
            $original = $root.'/tests/Fixtures/ResourceTemplate/raw-stitch/package.zip';
        }
        $destination = $root.'/tests/Fixtures/ResourceTemplate';
        if (! $files->isFile($original) || ! $files->isFile($sourceRoot.'/template.json')) {
            throw new RuntimeException('Cần normalized folder và original Stitch ZIP để tạo baseline.');
        }

        foreach (['normalized', 'raw-stitch', 'expected', 'invalid-screenshots'] as $directory) {
            $files->ensureDirectoryExists($destination.'/'.$directory);
        }

        $entries = [];
        foreach ($files->allFiles($sourceRoot) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            if ($relative !== 'manifest.json') {
                $entries['flowtask/'.$relative] = $files->get($file->getPathname());
            }
        }
        ksort($entries, SORT_STRING);
        $this->writeZip($destination.'/normalized/package.zip', $entries);
        $rawDestination = $destination.'/raw-stitch/package.zip';
        if ($original !== $rawDestination && ! $files->copy($original, $rawDestination)) {
            throw new RuntimeException('Không sao chép được raw Stitch fixture.');
        }

        $manifest = json_decode($files->get($sourceRoot.'/template.json'), true, 16, JSON_THROW_ON_ERROR);
        $this->writeJson($destination.'/expected/normalized-manifest.json', $manifest);

        // Đây là baseline discovery đã chốt, không phải implementation của parser TPL-03.
        $folders = ['calendar_flowtask', 'clients_flowtask', 'documents_flowtask', 'kanban_board_flowtask',
            'projects_flowtask', 'reports_flowtask', 'settings_flowtask', 'team_flowtask', 'time_tracking_flowtask'];
        $screens = [];
        foreach ($folders as $folder) {
            $screens[] = [
                'slug' => str_replace('_', '-', $folder),
                'title' => ucwords(str_replace('_', ' ', $folder)),
                'path' => $folder.'/code.html',
                'screenshot' => $folder.'/screen.png',
            ];
        }
        $this->writeJson($destination.'/expected/raw-discovery.json', [
            'discovery_version' => 1,
            'source_root' => 'stitch_full_page_ui_design',
            'manifest_present' => false,
            'entry_screen' => null,
            'requires_entry_selection' => true,
            'screens' => $screens,
            'ignored_documents' => ['vibrant_saas_productivity/DESIGN.md'],
            'warning_codes' => ['manifest_missing', 'entry_selection_required', 'external_dependency', 'placeholder_link'],
        ]);

        // Mẫu weather cũ không có trong workspace; tạo rõ 5 synthetic placeholder 28 byte.
        for ($index = 1; $index <= 5; $index++) {
            $files->put($destination.'/invalid-screenshots/placeholder-'.$index.'.png', str_pad('SCREENSHOT UNAVAILABLE '.$index, 28, '.'));
        }

        $inventory = [];
        foreach ($files->allFiles($destination) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            if (in_array($relative, ['FixtureBuilder.php', 'README.md', '.gitattributes', 'expected/inventory.json'], true)) {
                continue;
            }
            $inventory[$relative] = ['bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
        }
        ksort($inventory, SORT_STRING);
        $this->writeJson($destination.'/expected/inventory.json', $inventory);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi ZIP fixture có relative path dùng slash
     * =====================================================================
     * INPUT: Output path cố định trong fixture folder và map entry => bytes.
     * OUTPUT: ZIP fixture đóng hoàn chỉnh, không có directory entry dư.
     * SIDE EFFECT: Ghi/thay archive ở output path; không extract input hoặc chạy code.
     * EXCEPTION/TRANSACTION: RuntimeException nếu mở/thêm entry/đóng ZIP thất bại; không DB.
     * =====================================================================
     */
    private function writeZip(string $path, array $entries): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Không tạo được fixture ZIP.');
        }
        try {
            foreach ($entries as $name => $bytes) {
                if (! $zip->addFromString($name, $bytes)) {
                    throw new RuntimeException('Không ghi được entry fixture.');
                }
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('Không đóng được fixture ZIP.');
            }
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Ghi expected JSON dễ review
     * =====================================================================
     * INPUT: Output path dưới fixture folder và payload đã chọn.
     * OUTPUT: JSON UTF-8 pretty print với newline cuối file.
     * SIDE EFFECT: Ghi expected output; không gọi network/database.
     * EXCEPTION/TRANSACTION: JSON exception/RuntimeException khi encode/write lỗi; không DB.
     * =====================================================================
     */
    private function writeJson(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json."\n") === false) {
            throw new RuntimeException('Không ghi được expected fixture JSON.');
        }
    }
}
