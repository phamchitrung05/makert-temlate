<?php

namespace App\Services\Media;

use App\Enums\MediaAssetKind;
use App\Enums\MediaAssetVisibility;
use App\Models\AiImport;
use App\Models\MediaAsset;
use App\Models\User;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xác thực ảnh MediaLibrary thật xuất hiện trong HTML bài viết.
 * =====================================================================
 * HTML client phải mang asset ID và URL thuộc đúng file public. Service chỉ
 * đọc dữ liệu; caller đồng bộ usage trong transaction lưu Post/candidate.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - validate(): kiểm ảnh, quyền attach, URL và trả ID theo thứ tự nội dung.
 * - referencedIds(): đọc ID để bảo vệ asset khi dọn candidate, không cấp quyền.
 * - isReferencedByRetainedAiRun(): tìm tham chiếu trong run/snapshot còn retention.
 * - references(): đọc ref bảo thủ trong JSON đã lưu, không thay authorization.
 * - images(): parse HTML trong DOM tách rời và trả các node ảnh.
 * - document(): đọc HTML không thực thi, giữ cấu hình libxml.
 * - urls(): lấy URL gốc/conversion đã hoàn thành của asset.
 * - fail(): trả lỗi content 422 mà không lộ đường dẫn file nội bộ.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTML raw hoặc đã sanitize và actor đang lưu nội dung.
 * - OUTPUT: list<int> asset được dùng; lỗi quyền hoặc validation khi ref sai.
 * =====================================================================
 */
final class ContentMediaReferenceService
{
    /**
     * =====================================================================
     * CHỨC NĂNG: Kiểm ảnh inline và suy ra usage từ HTML, không tin gallery client.
     * =====================================================================
     * INPUT: HTML và User đang lưu/apply.
     * OUTPUT: ID không trùng theo thứ tự ảnh; ValidationException hoặc lỗi quyền.
     * SIDE EFFECT: Đọc database/policy; khóa asset theo ID khi caller đã mở transaction.
     * =====================================================================
     */
    public function validate(string $html, User $actor): array
    {
        if (trim($html) === '') {
            return [];
        }
        $document = $this->document($html);
        if ($document->getElementsByTagName('picture')->length > 0) {
            $this->fail('Ảnh nội dung chưa hỗ trợ picture/source; dùng img với URL MediaLibrary đã chọn.');
        }
        foreach ($document->getElementsByTagName('source') as $source) {
            if ($source->hasAttribute('srcset')) {
                $this->fail('Ảnh nội dung chưa hỗ trợ source/srcset; dùng URL MediaLibrary trong img.');
            }
        }
        $references = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            foreach ($image->attributes as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'on')) {
                    $this->fail('Ảnh nội dung không được chứa thuộc tính thực thi sự kiện.');
                }
            }
            if ($image->hasAttribute('srcset')) {
                $this->fail('Ảnh nội dung chưa hỗ trợ srcset; dùng một URL file MediaLibrary đã chọn.');
            }
            $id = $image->getAttribute('data-media-asset-id');
            if (! preg_match('/^[1-9][0-9]*$/D', $id) || strlen($id) > 18) {
                $this->fail('Chọn ảnh trong MediaLibrary để nội dung có tham chiếu asset hợp lệ.');
            }
            $references[] = ['id' => (int) $id, 'url' => trim($image->getAttribute('src'))];
        }
        $query = MediaAsset::query()->with('media')->whereKey(array_column($references, 'id'))->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $assets = $query->get()->keyBy('id');
        $ids = [];
        foreach ($references as $reference) {
            $asset = $assets->get($reference['id']);
            if (! $asset || $asset->kind !== MediaAssetKind::Image || $asset->visibility !== MediaAssetVisibility::Public) {
                $this->fail('Ảnh trong nội dung phải là asset public còn tồn tại.');
            }
            Gate::forUser($actor)->authorize('attach', $asset);
            if ($reference['url'] === '' || ! in_array($reference['url'], $this->urls($asset), true)) {
                $this->fail('URL ảnh trong nội dung không khớp file của MediaLibrary.');
            }
            $ids[] = $reference['id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Đọc ref đã lưu để cleanup không xóa file candidate còn giữ.
     * =====================================================================
     * INPUT: HTML snapshot/candidate; không dùng kết quả này thay validate().
     * OUTPUT: list<int> ID dương không trùng; bỏ qua ref không hợp lệ.
     * SIDE EFFECT: Không truy vấn database hoặc cấp quyền asset.
     * =====================================================================
     */
    public function referencedIds(string $html): array
    {
        $ids = [];
        foreach ($this->images($html) as $image) {
            $id = $image->getAttribute('data-media-asset-id');
            if (preg_match('/^[1-9][0-9]*$/D', $id) && strlen($id) <= 18) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Bảo vệ asset được candidate/run hoặc source snapshot còn giữ.
     * =====================================================================
     * INPUT: ID asset và UUID run bỏ qua khi cleaner đang dọn chính run đó;
     * input_json.parent_draft_snapshot của child queued cũng được đọc đệ quy.
     * OUTPUT: bool; bảo vệ ready/running/failed/cancelled còn retention để retry.
     * SIDE EFFECT: Đọc JSON theo cursor; caller khóa asset trước khi quyết định xóa.
     * =====================================================================
     */
    public function isReferencedByRetainedAiRun(int $assetId, ?string $exceptRunId = null): bool
    {
        $runs = AiImport::query()->when($exceptRunId !== null, fn ($query) => $query->whereKeyNot($exceptRunId))
            ->where('status', '!=', 'expired')->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })->select(['id', 'input_json', 'source_meta_json', 'result_json'])->cursor();
        foreach ($runs as $run) {
            if ($this->references([$run->input_json, $run->source_meta_json, $run->result_json], $assetId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Tìm ID ảnh/thumbnail và HTML inline trong JSON persisted.
     * =====================================================================
     * INPUT: Payload run đã lưu và asset ID cần kiểm tra.
     * OUTPUT: bool; không dùng làm chứng minh quyền hoặc URL hợp lệ.
     * SIDE EFFECT: Chỉ đọc JSON/DOM memory, không gọi provider/tải ảnh.
     * =====================================================================
     */
    private function references(mixed $payload, int $assetId): bool
    {
        if (is_string($payload)) {
            return str_contains(strtolower($payload), '<img') && in_array($assetId, $this->referencedIds($payload), true);
        }
        if (! is_array($payload)) {
            return false;
        }
        foreach ($payload as $key => $value) {
            if (in_array($key, ['media_asset_id', 'asset_id'], true) && is_numeric($value) && (int) $value === $assetId) {
                return true;
            }
            if ($key === 'content_image_ids' && is_array($value) && in_array($assetId, array_map('intval', $value), true)) {
                return true;
            }
            if ($this->references($value, $assetId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Parse ảnh trong DOM không thực thi HTML và giữ cấu hình libxml.
     * =====================================================================
     * INPUT: HTML UTF-8 bất kỳ.
     * OUTPUT: list<\DOMElement> trong thứ tự nội dung.
     * SIDE EFFECT: Không truy cập URL/file từ HTML.
     * =====================================================================
     */
    private function images(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        return iterator_to_array($this->document($html)->getElementsByTagName('img'));
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Parse HTML tách rời để kiểm node và thuộc tính ảnh trước sanitize.
     * =====================================================================
     * INPUT: HTML không rỗng.
     * OUTPUT: DOMDocument; không thay đổi định dạng HTML lưu của manual Post.
     * SIDE EFFECT: Không thực thi script/tải tài nguyên; khôi phục cấu hình libxml.
     * =====================================================================
     */
    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Lấy allowlist URL file và conversion thực sự đã được tạo.
     * =====================================================================
     * INPUT: MediaAsset public đã load media.
     * OUTPUT: list<string>; rỗng nếu asset chưa có file.
     * SIDE EFFECT: Đọc metadata/file URL; không tạo conversion hoặc tải ảnh.
     * =====================================================================
     */
    private function urls(MediaAsset $asset): array
    {
        $media = $asset->getFirstMedia('library');
        if (! $media) {
            return [];
        }
        $urls = [$media->getUrl()];
        foreach (['thumb', 'web', 'featured', 'og'] as $conversion) {
            if ($media->hasGeneratedConversion($conversion)) {
                $urls[] = $media->getUrl($conversion);
            }
        }

        return $urls;
    }

    /**
     * =====================================================================
     * CHỨC NĂNG: Báo lỗi tham chiếu ảnh bằng field content cho form biên tập.
     * =====================================================================
     * INPUT: Thông báo an toàn.
     * OUTPUT: Không trả về; ném ValidationException 422.
     * =====================================================================
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['content' => $message]);
    }
}
