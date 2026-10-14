<?php

namespace App\Http\Controllers\Public;

use App\Enums\ResourceType;
use App\Models\Resource;
use Illuminate\Http\Response;

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Xuất XML sitemap cho Resource Template public
 * CÁC HÀM/METHOD TRONG FILE: __invoke(), xmlEscape().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): public resources -> XML bounded/noindex.
 * =====================================================================
 */
final class SitemapController
{
    /** Input: không có request data. Output: XML chỉ gồm URL primary indexable. */
    public function __invoke(): Response
    {
        $urls = Resource::query()
            ->publiclyVisible()
            ->where('type', ResourceType::Template)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->with(['slugs', 'seoMetadata'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Resource $resource): bool => $resource->seoMetadata?->robots_index !== false && $resource->primarySlug() !== null)
            ->map(fn (Resource $resource): string => '<url><loc>'.$this->xmlEscape(route('public.resources.show', ['slug' => $resource->primarySlug()->slug])).'</loc><lastmod>'.$resource->updated_at?->toAtomString().'</lastmod></url>')
            ->implode('');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /** Input: URL string. Output: XML escaped value. */
    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
