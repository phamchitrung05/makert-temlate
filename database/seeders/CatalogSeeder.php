<?php

namespace Database\Seeders;

use App\Enums\TechnologyType;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Technology;
use Illuminate\Database\Seeder;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Seed cây danh mục, tag và công nghệ nền tảng
 * =====================================================================
 *
 * Seeder tạo dữ liệu taxonomy tối thiểu để admin có sẵn lựa chọn khi tạo
 * resource đầu tiên. Tên danh mục và tag cố định để seeder idempotent: chạy
 * lại nhiều lần vẫn cho cùng một cây dữ liệu, không nhân bản bản ghi.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - run(): tạo danh mục cha – con, tag và công nghệ nếu chưa tồn tại
 * - categories(): cây danh mục dạng mảng lồng nhau
 * - tags(): danh sách tên tag
 * - technologies(): danh sách công nghệ kèm nhóm
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : không có; dữ liệu nằm ngay trong seeder
 * - OUTPUT: database có taxonomy nền tảng cho form admin
 *
 * SIDE EFFECT:
 * - INSERT categories, tags và technologies nếu chưa tồn tại
 *
 * EXCEPTION/TRANSACTION:
 * - Không mở transaction; dùng firstOrCreate nên chạy lại vẫn an toàn
 * =====================================================================
 */
class CatalogSeeder extends Seeder
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Seed toàn bộ taxonomy nền tảng
     * =====================================================================
     *
     * SIDE EFFECT:
     * - INSERT bản ghi thiếu vào categories, tags và technologies
     */
    public function run(): void
    {
        $this->seedCategories($this->categories());
        $this->seedTags();
        $this->seedTechnologies();
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo cây danh mục từ cấu trúc lồng nhau
     * =====================================================================
     *
     * INPUT:
     * - $tree: mảng danh mục cha, mỗi phần tử có thể chứa khoá `children`
     *
     * SIDE EFFECT:
     * - INSERT categories và liên kết parent_id
     */
    private function seedCategories(array $tree, ?int $parentId = null): void
    {
        foreach ($tree as $node) {
            $category = Category::query()->firstOrCreate(
                ['name' => $node['name']],
                [
                    'parent_id' => $parentId,
                    'description' => $node['description'] ?? null,
                    'sort_order' => $node['sort_order'] ?? 0,
                ],
            );

            if (isset($node['children'])) {
                $this->seedCategories($node['children'], $category->id);
            }
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo danh sách tag nền tảng
     * =====================================================================
     *
     * SIDE EFFECT:
     * - INSERT tags còn thiếu
     */
    private function seedTags(): void
    {
        foreach ($this->tags() as $name) {
            Tag::query()->firstOrCreate(['name' => $name]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tạo danh sách công nghệ nền tảng
     * =====================================================================
     *
     * SIDE EFFECT:
     * - INSERT technologies còn thiếu theo cặp name + type
     */
    private function seedTechnologies(): void
    {
        foreach ($this->technologies() as $name => $type) {
            Technology::query()->firstOrCreate([
                'name' => $name,
                'type' => $type,
            ]);
        }
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Định nghĩa cây danh mục mẫu
     * =====================================================================
     *
     * OUTPUT:
     * - array<int, array>: cây danh mục dùng cho tài nguyên số
     */
    private function categories(): array
    {
        return [
            [
                'name' => 'Giao diện quản trị',
                'description' => 'Bộ giao diện quản trị hoàn chỉnh.',
                'sort_order' => 1,
                'children' => [
                    ['name' => 'Vue', 'sort_order' => 1],
                    ['name' => 'React', 'sort_order' => 2],
                    ['name' => 'Laravel', 'sort_order' => 3],
                ],
            ],
            [
                'name' => 'Giao diện website',
                'description' => 'Template cho website công khai.',
                'sort_order' => 2,
                'children' => [
                    ['name' => 'Landing page', 'sort_order' => 1],
                    ['name' => 'E-commerce', 'sort_order' => 2],
                    ['name' => 'Blog', 'sort_order' => 3],
                ],
            ],
            [
                'name' => 'Thành phần giao diện',
                'description' => 'UI kit và component dùng lại được.',
                'sort_order' => 3,
                'children' => [
                    ['name' => 'UI kit', 'sort_order' => 1],
                    ['name' => 'Bộ icon', 'sort_order' => 2],
                ],
            ],
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Định nghĩa danh sách tag mẫu
     * =====================================================================
     *
     * OUTPUT:
     * - list<string>: tên tag dùng cho tài nguyên số
     */
    private function tags(): array
    {
        return [
            'responsive',
            'dark-mode',
            'rtl',
            'saas',
            'ecommerce',
            'dashboard',
            'boilerplate',
        ];
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Định nghĩa danh sách công nghệ mẫu
     * =====================================================================
     *
     * OUTPUT:
     * - array<string, string>: tên công nghệ => nhóm tương ứng
     */
    private function technologies(): array
    {
        return [
            'Vue' => TechnologyType::Framework->value,
            'React' => TechnologyType::Framework->value,
            'Laravel' => TechnologyType::Framework->value,
            'PHP' => TechnologyType::Language->value,
            'JavaScript' => TechnologyType::Language->value,
            'TypeScript' => TechnologyType::Language->value,
            'Tailwind CSS' => TechnologyType::Css->value,
            'Vite' => TechnologyType::BuildTool->value,
            'Node.js' => TechnologyType::Runtime->value,
            'npm' => TechnologyType::PackageManager->value,
        ];
    }
}
