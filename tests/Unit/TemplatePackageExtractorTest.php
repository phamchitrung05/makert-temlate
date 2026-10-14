<?php

namespace Tests\Unit;

use App\Exceptions\MediaSecurityException;
use App\Services\Resources\TemplatePackageExtractor;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử extractor/parser ZIP Resource Template của TPL-03
 * =====================================================================
 *
 * Test kiểm tra extraction private, source-root, manifest canonical, raw
 * Stitch discovery, diagnostics dependency và các path/archive nguy hiểm.
 * Test chỉ dùng ZIP fixture hoặc ZIP tạm; không chạy HTML/JS và không ghi DB.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_normalized_package_extracts_manifest(): kiểm package chuẩn 18 screen
 * - test_raw_stitch_discovers_code_screens(): kiểm discovery 9 screen và entry selection
 * - test_extractor_rejects_unsafe_archive_path(): kiểm traversal/backslash/static file
 * - test_extractor_rejects_duplicate_normalized_path(): kiểm collision case-folding
 * - test_missing_local_dependency_is_blocking_and_external_query_is_redacted(): kiểm diagnostics
 * - extractFixture(): gọi extractor với private staging tạm
 * - zipWithEntries(): dựng ZIP input tối thiểu cho security test
 * - tempPath(): tạo path tạm duy nhất
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : ZIP fixture, ZIP synthetic và TemplatePackageExtractor
 * - OUTPUT: assertions cho manifest/discovery/security contract TPL-03
 * - SIDE EFFECT: tạo/xóa thư mục và ZIP tạm dưới system temp
 * - EXCEPTION/TRANSACTION: MediaSecurityException là kết quả mong đợi với input nguy hiểm; không DB transaction
 * =====================================================================
 */
class TemplatePackageExtractorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_dir($path)) {
                File::deleteDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Parse normalized fixture có manifest canonical
     * =====================================================================
     * INPUT: normalized/package.zip snapshot 18 screen.
     * OUTPUT: Manifest valid, source root flowtask và screen order giữ nguyên.
     * SIDE EFFECT: Tạo private staging run rồi test cleanup.
     * EXCEPTION/TRANSACTION: Không DB transaction; lỗi parser làm test fail.
     * =====================================================================
     */
    public function test_normalized_package_extracts_manifest(): void
    {
        $result = $this->extractFixture('normalized/package.zip');

        $this->assertTrue($result['valid'], implode(' | ', $result['errors']));
        $this->assertTrue($result['manifest_present']);
        $this->assertSame('flowtask', $result['source_root']);
        $this->assertSame('home', $result['entry_screen']);
        $this->assertFalse($result['requires_entry_selection']);
        $this->assertCount(18, $result['screens']);
        $this->assertSame('index.html', $result['screens'][0]['source_path']);
        $this->assertContains('index.html', $result['files']);
        $this->assertNotEmpty($result['diagnostics']['local_dependencies']);
        $this->assertDirectoryExists($result['staging_path']);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Discovery raw Stitch không có template.json
     * =====================================================================
     * INPUT: raw-stitch/package.zip có 9 thư mục code.html.
     * OUTPUT: 9 screen stable, source root đúng và cần chọn entry.
     * SIDE EFFECT: Tạo private staging run rồi test cleanup.
     * EXCEPTION/TRANSACTION: Không DB transaction.
     * =====================================================================
     */
    public function test_raw_stitch_discovers_code_screens(): void
    {
        $result = $this->extractFixture('raw-stitch/package.zip');

        $this->assertTrue($result['valid'], implode(' | ', $result['errors']));
        $this->assertFalse($result['manifest_present']);
        $this->assertSame('stitch_full_page_ui_design', $result['source_root']);
        $this->assertNull($result['entry_screen']);
        $this->assertTrue($result['requires_entry_selection']);
        $this->assertCount(9, $result['screens']);
        $this->assertSame('calendar-flowtask', $result['screens'][0]['slug']);
        $this->assertSame('calendar_flowtask/code.html', $result['screens'][0]['path']);
        $this->assertSame('calendar_flowtask/code.html', $result['screens'][0]['source_path']);
        $this->assertSame('calendar_flowtask/screen.png', $result['screens'][0]['screenshot']);
        $this->assertStringContainsString('manifest_missing', implode(' | ', $result['warnings']));
        $this->assertStringContainsString('entry_selection_required', implode(' | ', $result['warnings']));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn traversal, backslash và static executable trong ZIP
     * =====================================================================
     * INPUT: Synthetic ZIP chứa path nguy hiểm.
     * OUTPUT: Extractor ném MediaSecurityException trước khi publish staging.
     * SIDE EFFECT: Tạo/xóa ZIP tạm; không để lại run staging lỗi.
     * EXCEPTION/TRANSACTION: MediaSecurityException là kết quả mong đợi.
     * =====================================================================
     */
    #[DataProvider('unsafeEntryProvider')]
    public function test_extractor_rejects_unsafe_archive_path(string $entry): void
    {
        $zipPath = $this->zipWithEntries([$entry => '<html></html>']);

        $this->expectException(MediaSecurityException::class);
        app(TemplatePackageExtractor::class)->extract($zipPath, $this->tempPath('staging'));
    }

    /**
     * Cung cấp các entry phải bị block trước extraction.
     *
     * Input: Không có.
     * Output: path traversal/backslash/server-side file.
     * Side effect: Không có.
     * Exception/transaction: Không có.
     */
    public static function unsafeEntryProvider(): array
    {
        return [
            'dot dot' => ['../index.html'],
            'backslash' => ['pages\\index.html'],
            'php' => ['index.php'],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn hai entry trùng sau case-folding
     * =====================================================================
     * INPUT: ZIP có Index.html và index.html.
     * OUTPUT: MediaSecurityException cho collision path.
     * SIDE EFFECT: Tạo/xóa ZIP tạm.
     * EXCEPTION/TRANSACTION: MediaSecurityException là kết quả mong đợi.
     * =====================================================================
     */
    public function test_extractor_rejects_duplicate_normalized_path(): void
    {
        $zipPath = $this->zipWithEntries([
            'Index.html' => '<html></html>',
            'index.html' => '<html></html>',
        ]);

        $this->expectException(MediaSecurityException::class);
        app(TemplatePackageExtractor::class)->extract($zipPath, $this->tempPath('staging'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Báo missing local dependency và redact URL ngoài
     * =====================================================================
     * INPUT: HTML có link local thiếu và external URL chứa query/fragment.
     * OUTPUT: valid=false; external diagnostic không giữ query/fragment.
     * SIDE EFFECT: Tạo/xóa ZIP tạm.
     * EXCEPTION/TRANSACTION: Không DB transaction.
     * =====================================================================
     */
    public function test_missing_local_dependency_is_blocking_and_external_query_is_redacted(): void
    {
        $zipPath = $this->zipWithEntries([
            'template.json' => json_encode([
                'schema_version' => 1,
                'entry_screen' => 'home',
                'screens' => [['slug' => 'home', 'title' => 'Home', 'path' => 'index.html']],
            ], JSON_THROW_ON_ERROR),
            'index.html' => '<html><head><link rel="stylesheet" href="missing.css"><script src="https://cdn.example.test/app.js?token=secret#x"></script></head></html>',
        ]);

        $result = app(TemplatePackageExtractor::class)->extract($zipPath, $this->tempPath('staging'));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('missing_local_dependency', implode(' | ', $result['errors']));
        $this->assertSame('cdn.example.test', $result['diagnostics']['external_dependencies'][0]['host']);
        $this->assertSame('https://cdn.example.test/app.js', $result['diagnostics']['external_dependencies'][0]['url']);
        $this->assertStringNotContainsString('secret', json_encode($result['diagnostics']));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Gọi extractor với fixture và private staging tạm
     * =====================================================================
     * INPUT: Relative fixture path.
     * OUTPUT: Result parser/discovery của extractor.
     * SIDE EFFECT: Tạo staging root dưới system temp.
     * EXCEPTION/TRANSACTION: Không DB transaction.
     * =====================================================================
     */
    private function extractFixture(string $fixture): array
    {
        return app(TemplatePackageExtractor::class)->extract(
            base_path('tests/Fixtures/ResourceTemplate/'.$fixture),
            $this->tempPath('staging'),
        );
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo ZIP synthetic từ map path/content
     * =====================================================================
     * INPUT: associative array path => content.
     * OUTPUT: absolute ZIP path.
     * SIDE EFFECT: Ghi file ZIP tạm vào system temp.
     * EXCEPTION/TRANSACTION: RuntimeException nếu ZipArchive không tạo được.
     * =====================================================================
     */
    private function zipWithEntries(array $entries): string
    {
        $path = $this->tempPath('fixture', false).'.zip';
        $this->temporaryPaths[] = $path;
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Không tạo được ZIP test.');
        }
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo path tạm và đăng ký cleanup
     * =====================================================================
     * INPUT: Prefix và cờ directory.
     * OUTPUT: Absolute path chưa tồn tại.
     * SIDE EFFECT: Đăng ký cleanup trong tearDown.
     * EXCEPTION/TRANSACTION: Không có.
     * =====================================================================
     */
    private function tempPath(string $prefix, bool $directory = true): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'resource-template-'.$prefix.'-'.bin2hex(random_bytes(8));
        $this->temporaryPaths[] = $path;
        if ($directory) {
            File::makeDirectory($path, 0700, true);
        }

        return $path;
    }
}
