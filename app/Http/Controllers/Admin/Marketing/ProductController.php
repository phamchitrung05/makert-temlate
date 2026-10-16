<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Enums\MediaAssetField;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Marketing\ProductRequest;
use App\Http\Resources\Marketing\ProductResource;
use App\Http\Responses\BaseResponse;
use App\Models\Product;
use App\Services\MediaAssetUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: CRUD product/facts/reference image cho AI context.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: index(), show(), store(), update(), destroy(),
 * syncFacts(), payload(), actor().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): admin request -> product JSON bounded;
 * reference media dùng field product.reference_images, không chứa token/provider.
 * =====================================================================
 */
final class ProductController extends Controller
{
    /** Input: filter/pagination. Output: product list. Side effect: read DB. */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->with(['facts', 'mediaAssetUsages.mediaAsset.media'])->orderByDesc('created_at');
        if (filled($request->string('search')->toString())) {
            $term = addcslashes($request->string('search')->toString(), '%_');
            $query->where('name', 'like', '%'.$term.'%');
        }

        $page = $query->paginate($this->resolvePerPage($request));

        return BaseResponse::paginated(ProductResource::collection($page), 'Danh sách product.');
    }

    /** Input: Product route binding. Output: product detail. */
    public function show(Product $product): JsonResponse
    {
        return BaseResponse::success($this->payload($product->load('facts', 'mediaAssetUsages.mediaAsset.media')), 'Chi tiết product.');
    }

    /** Input: ProductRequest. Output: HTTP 201 product. Side effect: DB/media transaction. */
    public function store(ProductRequest $request, MediaAssetUsageService $usages): JsonResponse
    {
        $product = DB::transaction(function () use ($request, $usages): Product {
            $data = $request->validated();
            $product = Product::query()->create([
                'name' => $data['name'], 'short_description' => $data['short_description'] ?? null,
                'default_cta' => $data['default_cta'] ?? null, 'prohibited_claims_json' => $data['prohibited_claims'] ?? [],
                'status' => $data['status'] ?? 'draft', 'created_by' => $this->actor($request)->getKey(), 'updated_by' => $this->actor($request)->getKey(),
            ]);
            $this->syncFacts($product, $data['facts'] ?? [], $this->actor($request)->getKey());
            if (array_key_exists('reference_asset_ids', $data)) {
                $usages->replaceByIds($this->actor($request), $product, MediaAssetField::ProductReferenceImages, $data['reference_asset_ids']);
            }

            return $product;
        });

        return BaseResponse::created($this->payload($product->load('facts', 'mediaAssetUsages.mediaAsset.media')), 'Đã tạo product.');
    }

    /** Input: ProductRequest/product. Output: updated product. */
    public function update(ProductRequest $request, Product $product, MediaAssetUsageService $usages): JsonResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data, $product, $request, $usages): void {
            $product->update([
                'name' => $data['name'], 'short_description' => $data['short_description'] ?? null,
                'default_cta' => $data['default_cta'] ?? null, 'prohibited_claims_json' => $data['prohibited_claims'] ?? [],
                'status' => $data['status'] ?? $product->status, 'updated_by' => $this->actor($request)->getKey(),
            ]);
            if (array_key_exists('facts', $data)) {
                $this->syncFacts($product, $data['facts'], $this->actor($request)->getKey());
            }
            if (array_key_exists('reference_asset_ids', $data)) {
                $usages->replaceByIds($this->actor($request), $product, MediaAssetField::ProductReferenceImages, $data['reference_asset_ids']);
            }
        });

        return BaseResponse::success($this->payload($product->fresh()->load('facts', 'mediaAssetUsages.mediaAsset.media')), 'Đã cập nhật product.');
    }

    /** Input: product/actor. Output: HTTP 204. Side effect: soft delete after detach. */
    public function destroy(Request $request, Product $product, MediaAssetUsageService $usages): \Symfony\Component\HttpFoundation\Response
    {
        DB::transaction(function () use ($product, $request, $usages): void {
            $usages->detachAll($this->actor($request), $product);
            $product->delete();
        });

        return BaseResponse::noContent();
    }

    /** Input: product/facts payload. Output: persisted facts. Side effect: replaces facts. */
    private function syncFacts(Product $product, array $facts, int $actorId): void
    {
        $product->facts()->delete();
        foreach ($facts as $fact) {
            $product->facts()->create([
                'fact_key' => $fact['key'], 'fact_value' => $fact['value'], 'source' => $fact['source'],
                'verified' => (bool) ($fact['verified'] ?? false), 'verified_by' => ($fact['verified'] ?? false) ? $actorId : null,
                'verified_at' => ($fact['verified'] ?? false) ? now() : null, 'version' => 1,
                'is_allowed_for_ai' => (bool) ($fact['is_allowed_for_ai'] ?? true),
            ]);
        }
    }

    /** Input: Product. Output: bounded JSON payload. */
    private function payload(Product $product): array
    {
        return [
            'id' => (int) $product->id, 'name' => $product->name, 'short_description' => $product->short_description,
            'default_cta' => $product->default_cta, 'status' => $product->status?->value,
            'prohibited_claims' => $product->prohibited_claims_json ?? [],
            'facts' => $product->relationLoaded('facts') ? $product->facts->map(fn ($fact): array => [
                'id' => (int) $fact->id, 'key' => $fact->fact_key, 'value' => $fact->fact_value,
                'source' => $fact->source, 'verified' => (bool) $fact->verified, 'is_allowed_for_ai' => (bool) $fact->is_allowed_for_ai,
            ])->values()->all() : [],
            'reference_asset_ids' => $product->relationLoaded('mediaAssetUsages')
                ? $product->mediaAssetUsages
                    ->filter(fn ($usage): bool => $usage->field === MediaAssetField::ProductReferenceImages
                        && $usage->mediaAsset !== null)
                    ->sortBy('sort_order')
                    ->pluck('media_asset_id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all()
                : [],
        ];
    }

    /** Input: Request. Output: admin User. */
    private function actor(Request $request): \App\Models\User
    {
        $user = $request->user();
        abort_unless($user instanceof \App\Models\User, 401);

        return $user;
    }
}
