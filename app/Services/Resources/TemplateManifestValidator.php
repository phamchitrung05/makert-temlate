<?php

namespace App\Services\Resources;

use JsonException;
use stdClass;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm tra manifest v1 của Resource Template
 * =====================================================================
 *
 * Validator khóa contract JSON đầu vào trước khi TPL-03 xây parser ZIP.
 * Nó chỉ kiểm tra cấu trúc, field allowlist, slug/path (kể cả Windows device
 * name), entry screen và
 * quan hệ với danh sách file đã discover; không đọc HTML, không giải nén
 * archive và không ghi database.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - validateJson(): kiểm JSON bounded và giữ phân biệt object/list
 * - validate(): trả manifest chuẩn hóa, lỗi blocking và warning
 * - validateMetadata(): kiểm tra metadata tùy chọn của package
 * - validateScreens(): kiểm tra screen, path, slug và screenshot
 * - validateFieldSet(): chặn field ngoài allowlist
 * - validateString(): kiểm tra kiểu và giới hạn chuỗi
 * - normalizeRelativePath(): kiểm tra path dùng chung cho manifest và extractor
 * - normalizeAvailablePaths(): dựng set file tồn tại, không đọc filesystem
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : JSON hoặc array manifest và relative path đã discover (null khi chỉ kiểm schema)
 * - OUTPUT: array{valid: bool, errors: list<string>, warnings: list<string>, manifest: array}
 * - SIDE EFFECT: Không có; validator thuần, có thể dùng lại trong parser và FormRequest
 * - EXCEPTION/TRANSACTION: Không tự ném exception hoặc mở transaction; lỗi trả trong output
 * =====================================================================
 */
final class TemplateManifestValidator
{
    private const SCHEMA_VERSION = 1;

    private const MAX_JSON_BYTES = 262144;

    private const MAX_SCREENS = 200;

    private const MAX_PATH_LENGTH = 512;

    private const MAX_NAME_LENGTH = 120;

    private const MAX_TITLE_LENGTH = 160;

    private const MAX_VERSION_LENGTH = 64;

    private const MAX_DESCRIPTION_LENGTH = 2000;

    private const TOP_LEVEL_FIELDS = [
        'name',
        'title',
        'version',
        'description',
        'schema_version',
        'entry_screen',
        'screens',
    ];

    private const SCREEN_FIELDS = ['slug', 'title', 'path', 'screenshot'];

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc JSON bounded và giữ đúng kiểu object/list của schema
     * =====================================================================
     * INPUT: JSON UTF-8 tối đa 256 KiB, relative path list hoặc null để kiểm schema.
     * OUTPUT: Cùng result với validate(); JSON sai/không phải object trả valid=false.
     * SIDE EFFECT: Không đọc file, database, network hoặc queue.
     * EXCEPTION/TRANSACTION: JsonException được chuyển thành lỗi; không mở transaction.
     * =====================================================================
     */
    public function validateJson(string $json, ?array $availablePaths = null): array
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            return ['valid' => false, 'errors' => ['template.json vượt giới hạn 256 KiB.'], 'warnings' => [], 'manifest' => []];
        }

        try {
            $decoded = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['valid' => false, 'errors' => ['template.json phải là JSON UTF-8 hợp lệ.'], 'warnings' => [], 'manifest' => []];
        }

        if (! $decoded instanceof stdClass) {
            return ['valid' => false, 'errors' => ['template.json phải là object.'], 'warnings' => [], 'manifest' => []];
        }

        $manifest = (array) $decoded;
        if (isset($manifest['screens']) && is_array($manifest['screens'])) {
            $manifest['screens'] = array_map(
                fn ($screen) => $screen instanceof stdClass ? (array) $screen : $screen,
                $manifest['screens'],
            );
        }

        return $this->validate($manifest, $availablePaths);
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Validate manifest Resource Template theo schema v1
     * =====================================================================
     *
     * INPUT:
     * - $manifest: object JSON đã chuyển thành associative array.
     * - $availablePaths: relative path có trong package; null chỉ kiểm schema, [] là package rỗng.
     *
     * OUTPUT:
     * - valid=true khi không có lỗi blocking; warning không làm manifest invalid.
     * - manifest là bản đã chuẩn hóa path và giữ thứ tự screen đầu vào.
     *
     * SIDE EFFECT:
     * - Không đọc filesystem; không ghi dữ liệu hoặc sửa input.
     *
     * EXCEPTION/TRANSACTION:
     * - Không ném exception; input sai được trả về trong errors để caller chọn HTTP/queue behavior.
     * =====================================================================
     */
    public function validate(array $manifest, ?array $availablePaths = null): array
    {
        $errors = [];
        $warnings = [];
        $normalized = [];

        $this->validateFieldSet($manifest, self::TOP_LEVEL_FIELDS, 'manifest', $errors);

        if (! in_array($manifest['schema_version'] ?? null, [self::SCHEMA_VERSION, 1.0], true)) {
            $errors[] = 'manifest.schema_version phải bằng 1.';
        } else {
            $normalized['schema_version'] = self::SCHEMA_VERSION;
        }

        $this->validateMetadata($manifest, $normalized, $errors);

        $entryScreen = $manifest['entry_screen'] ?? null;
        if (! $this->validateString($entryScreen, 'manifest.entry_screen', 1, 120, $errors)
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $entryScreen) !== 1) {
            $errors[] = 'manifest.entry_screen phải dùng kebab-case ASCII.';
            $entryScreen = null;
        } else {
            $normalized['entry_screen'] = $entryScreen;
        }

        $screens = $manifest['screens'] ?? null;
        if (! is_array($screens) || ! array_is_list($screens) || $screens === []) {
            $errors[] = 'manifest.screens phải là mảng không rỗng.';
            $screens = [];
        } elseif (count($screens) > self::MAX_SCREENS) {
            $errors[] = 'manifest.screens không được vượt quá 200 screen.';
            $screens = [];
        }

        $normalizedScreens = $this->validateScreens($screens, $availablePaths, $errors, $warnings);
        $normalized['screens'] = $normalizedScreens;

        if ($entryScreen !== null && ! in_array($entryScreen, array_column($normalizedScreens, 'slug'), true)) {
            $errors[] = 'manifest.entry_screen phải trỏ tới một screen hợp lệ.';
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'manifest' => $normalized,
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra metadata tùy chọn của package
     * =====================================================================
     *
     * INPUT: Manifest gốc và mảng normalized đang được dựng.
     * OUTPUT: Bổ sung metadata hợp lệ vào normalized; lỗi được append vào errors.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không ném exception hoặc mở transaction.
     * =====================================================================
     */
    private function validateMetadata(array $manifest, array &$normalized, array &$errors): void
    {
        $limits = [
            'name' => self::MAX_NAME_LENGTH,
            'title' => self::MAX_TITLE_LENGTH,
            'version' => self::MAX_VERSION_LENGTH,
            'description' => self::MAX_DESCRIPTION_LENGTH,
        ];

        foreach ($limits as $field => $maxLength) {
            if (! array_key_exists($field, $manifest)) {
                continue;
            }

            if ($this->validateString($manifest[$field], "manifest.{$field}", 1, $maxLength, $errors)) {
                $normalized[$field] = trim($manifest[$field]);
            }
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra danh sách screen và screenshot
     * =====================================================================
     *
     * INPUT: Danh sách screen, relative path list/null và hai mảng lỗi/warning.
     * OUTPUT: Danh sách screen đã chuẩn hóa path.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không ném exception hoặc mở transaction.
     * =====================================================================
     */
    private function validateScreens(array $screens, ?array $availablePaths, array &$errors, array &$warnings): array
    {
        $available = $availablePaths === null ? null : $this->normalizeAvailablePaths($availablePaths);
        $normalized = [];
        $slugs = [];
        $paths = [];

        foreach ($screens as $index => $screen) {
            $prefix = "manifest.screens.{$index}";
            if (! is_array($screen) || array_is_list($screen)) {
                $errors[] = "{$prefix} phải là object.";

                continue;
            }

            $this->validateFieldSet($screen, self::SCREEN_FIELDS, $prefix, $errors);

            $slug = $screen['slug'] ?? null;
            if (! $this->validateString($slug, "{$prefix}.slug", 1, 120, $errors)
                || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
                $errors[] = "{$prefix}.slug phải dùng kebab-case ASCII.";
                $slug = null;
            } else {
                if (isset($slugs[$slug])) {
                    $errors[] = "Slug screen bị trùng: {$slug}.";
                }
                $slugs[$slug] = true;
            }

            $title = $screen['title'] ?? null;
            $titleValid = $this->validateString($title, "{$prefix}.title", 1, self::MAX_TITLE_LENGTH, $errors);

            $path = $screen['path'] ?? null;
            $normalizedPath = $this->normalizeRelativePath($path);
            $pathKey = $normalizedPath === null ? null : mb_strtolower($normalizedPath);
            if ($normalizedPath === null) {
                $errors[] = "{$prefix}.path phải là relative path an toàn.";
            } elseif (preg_match('/\.html?$/iD', $normalizedPath) !== 1) {
                $errors[] = "{$prefix}.path phải trỏ tới file HTML.";
            } elseif (isset($paths[$pathKey])) {
                $errors[] = "Path screen bị trùng: {$normalizedPath}.";
            } else {
                $paths[$pathKey] = true;

                if ($available !== null && ! isset($available[$normalizedPath])) {
                    $errors[] = "{$prefix}.path không tồn tại trong package: {$normalizedPath}.";
                }
            }

            $normalizedScreen = [];
            if ($slug !== null) {
                $normalizedScreen['slug'] = $slug;
            }
            if ($titleValid) {
                $normalizedScreen['title'] = trim($title);
            }
            if ($normalizedPath !== null) {
                $normalizedScreen['path'] = $normalizedPath;
            }

            if (! array_key_exists('screenshot', $screen)) {
                $warnings[] = "Screen {$slug} chưa có screenshot tùy chọn.";
            } else {
                $screenshot = $this->normalizeRelativePath($screen['screenshot']);
                if ($screenshot === null) {
                    $errors[] = "{$prefix}.screenshot phải là relative path an toàn.";
                } elseif (preg_match('/\.(?:png|jpe?g|webp|gif)$/iD', $screenshot) !== 1) {
                    $errors[] = "{$prefix}.screenshot phải là ảnh png, jpg, jpeg, webp hoặc gif.";
                } else {
                    $normalizedScreen['screenshot'] = $screenshot;
                    if ($available !== null && ! isset($available[$screenshot])) {
                        $warnings[] = "Screenshot của screen {$slug} không tồn tại: {$screenshot}.";
                    }
                }
            }

            $normalized[] = $normalizedScreen;
        }

        return $normalized;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chặn field ngoài allowlist của manifest
     * =====================================================================
     *
     * INPUT: Object array, danh sách field cho phép và tên path lỗi.
     * OUTPUT: Không trả giá trị; lỗi được append nếu có field dư.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không ném exception hoặc mở transaction.
     * =====================================================================
     */
    private function validateFieldSet(array $value, array $allowed, string $prefix, array &$errors): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $field) {
            $errors[] = "{$prefix}.{$field} không được phép trong manifest schema v1.";
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra chuỗi theo kiểu và giới hạn độ dài
     * =====================================================================
     *
     * INPUT: Giá trị field, tên field, độ dài tối thiểu/tối đa và mảng lỗi.
     * OUTPUT: true khi chuỗi hợp lệ.
     * SIDE EFFECT: Append lỗi validation khi giá trị không hợp lệ.
     * EXCEPTION/TRANSACTION: Không ném exception hoặc mở transaction.
     * =====================================================================
     */
    private function validateString(mixed $value, string $field, int $minLength, int $maxLength, array &$errors): bool
    {
        if (! is_string($value)) {
            $errors[] = "{$field} phải là chuỗi.";

            return false;
        }

        $length = mb_strlen($value);
        if (preg_match('/\S/u', $value) !== 1 || $length < $minLength || $length > $maxLength) {
            $errors[] = "{$field} phải dài từ {$minLength} đến {$maxLength} ký tự.";

            return false;
        }

        return true;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra và chuẩn hóa relative path trong package
     * =====================================================================
     *
     * INPUT: Path từ manifest, extractor hoặc danh sách file discover.
     * OUTPUT: Path dùng dấu slash hoặc null nếu path nguy hiểm.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Không ném exception hoặc mở transaction.
     * =====================================================================
     */
    public function normalizeRelativePath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || mb_strlen($path) > self::MAX_PATH_LENGTH) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if (preg_match('/^(?![.\s])[^\\\\\/:*?"<>|%#\x00-\x1F\x7F]+(?<![.\s])$/Du', $segment) !== 1) {
                return null;
            }

            if (preg_match('/^(?:con|prn|aux|nul|com[1-9¹²³]|lpt[1-9¹²³])(?:\.|$)/iu', $segment) === 1) {
                return null;
            }
        }

        return $path;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Chuẩn hóa danh sách file được discover
     * =====================================================================
     *
     * INPUT: Relative path list từ extractor/parser.
     * OUTPUT: Associative set để kiểm tra file tồn tại nhanh.
     * SIDE EFFECT: Không có.
     * EXCEPTION/TRANSACTION: Path sai bị bỏ qua; lỗi manifest được báo ở caller.
     * =====================================================================
     */
    private function normalizeAvailablePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $value = $this->normalizeRelativePath($path);
            if ($value !== null) {
                $normalized[$value] = true;
            }
        }

        return $normalized;
    }
}
