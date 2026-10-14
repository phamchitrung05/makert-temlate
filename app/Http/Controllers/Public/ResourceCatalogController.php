<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Enums\ResourceType;
use App\Services\Catalog\ResourceCatalogService;
use Illuminate\Http\Request;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Public Blade catalog/detail Resource Template
 * CÁC HÀM/METHOD TRONG FILE: index(), show(), preview().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): query/slug -> view model public bounded.
 * =====================================================================
 */
final class ResourceCatalogController extends Controller
{
    /** Input: q/type/page allowlist. Output: catalog view với paginator DTO. */
    public function index(Request $request, ResourceCatalogService $catalog)
    {
        $filters = $request->only(['q', 'type', 'page', 'per_page']);
        $filters['type'] = ResourceType::Template->value;
        $items = $catalog->paginate($filters)->through(fn ($resource): array => $catalog->card($resource));

        return view('public.resources.index', ['items' => $items, 'filters' => $filters]);
    }

    /** Input: primary/historical slug. Output: detail view hoặc 301/404. */
    public function show(string $slug, ResourceCatalogService $catalog)
    {
        $resolved = $catalog->resolveSlug($slug);
        abort_unless($resolved, 404);
        [$resource, $historical] = $resolved;
        if ($historical) {
            return redirect()->route('public.resources.show', ['slug' => $resource->primarySlug()?->slug], 301);
        }

        return view('public.resources.show', ['resource' => $catalog->detail($resource)]);
    }
}
