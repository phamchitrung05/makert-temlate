<?php

namespace Tests\Unit;

use App\Enums\MediaAssetField;
use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Models\ResourceVersion;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\TestCase;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử contract kind, visibility và field của Media Library
 * =====================================================================
 *
 * Test khóa danh sách field được phép, kind tương ứng, cardinality và morph
 * alias trước khi Task 2 tạo model/migration.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - test_media_asset_kind_and_visibility_values_are_stable(): kiểm tra enum chính
 * - test_media_asset_fields_define_kind_cardinality_and_morph_alias(): kiểm tra field contract
 * - test_resource_version_morph_alias_is_registered(): kiểm tra morph map
 * =====================================================================
 */
class MediaAssetContractTest extends TestCase
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra danh sách kind và visibility không bị đổi ngoài contract
     * =====================================================================
     *
     * OUTPUT:
     * - Enum trả đúng toàn bộ value được tài liệu Media Library công bố
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    public function test_media_asset_kind_and_visibility_values_are_stable(): void
    {
        $this->assertSame(['image', 'document', 'archive', 'video'], MediaAssetKind::values());
        $this->assertSame(['public', 'private'], MediaAssetVisibility::values());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra field map sang kind, cardinality và morph alias
     * =====================================================================
     *
     * OUTPUT:
     * - Mỗi field trả đúng rule attach mà API/backend sẽ dùng
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    public function test_media_asset_fields_define_kind_cardinality_and_morph_alias(): void
    {
        $this->assertSame([
            'post.thumbnail',
            'post.gallery',
            'post.og_image',
            'resource.cover',
            'resource.preview',
            'resource_version.package',
            'resource_version.documentation',
        ], MediaAssetField::values());

        $this->assertSame(MediaAssetKind::Image, MediaAssetField::ResourceCover->kind());
        $this->assertSame(MediaAssetKind::Image, MediaAssetField::PostOgImage->kind());
        $this->assertSame(MediaAssetKind::Image, MediaAssetField::PostGallery->kind());
        $this->assertTrue(MediaAssetField::PostGallery->allowsMultiple());
        $this->assertSame('post', MediaAssetField::PostGallery->linkableMorphAlias());
        $this->assertFalse(MediaAssetField::PostOgImage->allowsMultiple());
        $this->assertSame(MediaAssetKind::Archive, MediaAssetField::ResourceVersionPackage->kind());
        $this->assertTrue(MediaAssetField::ResourcePreview->allowsMultiple());
        $this->assertFalse(MediaAssetField::ResourceCover->allowsMultiple());
        $this->assertSame('resource_version', MediaAssetField::ResourceVersionPackage->linkableMorphAlias());
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm tra alias resource_version được đăng ký toàn cục
     * =====================================================================
     *
     * OUTPUT:
     * - Relation resolve alias resource_version về đúng model ResourceVersion
     *
     * SIDE EFFECT:
     * - Không có
     * =====================================================================
     */
    public function test_resource_version_morph_alias_is_registered(): void
    {
        $this->assertSame(ResourceVersion::class, Relation::getMorphedModel('resource_version'));
    }
}
