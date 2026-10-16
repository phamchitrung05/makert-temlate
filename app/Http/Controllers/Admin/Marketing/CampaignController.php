<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Marketing\CampaignRequest;
use App\Http\Responses\BaseResponse;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: CRUD campaign và product selection cho daily planner.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: index(), show(), store(), update(), destroy(),
 * syncProducts(), payload(), actor().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): admin request -> campaign JSON bounded.
 * =====================================================================
 */
final class CampaignController extends Controller
{
    /** Input: search/pagination. Output: campaign list. */
    public function index(Request $request): JsonResponse
    {
        $query = Campaign::query()->with('products:id,name')->orderByDesc('created_at');
        if (filled($request->string('search')->toString())) {
            $term = addcslashes($request->string('search')->toString(), '%_');
            $query->where('name', 'like', '%'.$term.'%');
        }
        $page = $query->paginate($this->resolvePerPage($request));

        return BaseResponse::paginated(\App\Http\Resources\Marketing\CampaignResource::collection($page), 'Danh sách campaign.');
    }

    /** Input: campaign binding. Output: campaign detail. */
    public function show(Campaign $campaign): JsonResponse
    {
        return BaseResponse::success($this->payload($campaign->load('products:id,name')), 'Chi tiết campaign.');
    }

    /** Input: CampaignRequest. Output: 201 campaign. */
    public function store(CampaignRequest $request): JsonResponse
    {
        $data = $request->validated();
        $campaign = DB::transaction(function () use ($data, $request): Campaign {
            $campaign = Campaign::query()->create([
                'name' => $data['name'], 'objective' => $data['objective'] ?? null, 'message' => $data['message'] ?? null,
                'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null,
                'timezone' => $data['timezone'] ?? 'Asia/Ho_Chi_Minh', 'status' => $data['status'] ?? 'draft',
                'created_by' => $this->actor($request)->getKey(), 'updated_by' => $this->actor($request)->getKey(),
            ]);
            $this->syncProducts($campaign, $data['product_ids'] ?? []);

            return $campaign;
        });

        return BaseResponse::created($this->payload($campaign->load('products:id,name')), 'Đã tạo campaign.');
    }

    /** Input: CampaignRequest/campaign. Output: updated campaign. */
    public function update(CampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data, $request, $campaign): void {
            $campaign->update([
                'name' => $data['name'], 'objective' => $data['objective'] ?? null, 'message' => $data['message'] ?? null,
                'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null,
                'timezone' => $data['timezone'] ?? $campaign->timezone, 'status' => $data['status'] ?? $campaign->status,
                'updated_by' => $this->actor($request)->getKey(),
            ]);
            if (array_key_exists('product_ids', $data)) {
                $this->syncProducts($campaign, $data['product_ids']);
            }
        });

        return BaseResponse::success($this->payload($campaign->fresh()->load('products:id,name')), 'Đã cập nhật campaign.');
    }

    /** Input: campaign. Output: HTTP 204. Side effect: soft delete. */
    public function destroy(Campaign $campaign): \Symfony\Component\HttpFoundation\Response
    {
        $campaign->delete();

        return BaseResponse::noContent();
    }

    /** Input: campaign/product IDs. Output: pivot rows. */
    private function syncProducts(Campaign $campaign, array $productIds): void
    {
        $campaign->products()->sync(array_values(array_unique(array_map('intval', $productIds))));
    }

    /** Input: Campaign. Output: bounded JSON. */
    private function payload(Campaign $campaign): array
    {
        return [
            'id' => (int) $campaign->id, 'name' => $campaign->name, 'objective' => $campaign->objective,
            'message' => $campaign->message, 'starts_at' => $campaign->starts_at?->toIso8601String(),
            'ends_at' => $campaign->ends_at?->toIso8601String(), 'timezone' => $campaign->timezone,
            'status' => $campaign->status?->value,
            'products' => $campaign->relationLoaded('products') ? $campaign->products->map(fn ($product): array => ['id' => (int) $product->id, 'name' => $product->name])->values()->all() : [],
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
