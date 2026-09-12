<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BlogSitemapController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->shopAtelierIdOrAbort($request);
        $base = rtrim($request->input('site_url') ?: env('FRONTEND_URL', config('app.url')), '/');
        $shopPath = '/shikshoo';

        $urls = [];
        $urls[] = [
            'loc' => $base.$shopPath.'/blog',
            'changefreq' => 'daily',
            'priority' => '0.9',
            'lastmod' => optional(BlogPost::where('atelier_id', $atelierId)->published()->max('updated_at')) ?: now()->toAtomString(),
        ];

        $categories = BlogCategory::where('atelier_id', $atelierId)
            ->where('is_active', true)
            ->orderBy('order')
            ->get(['slug', 'updated_at']);

        foreach ($categories as $category) {
            $urls[] = [
                'loc' => $base.$shopPath.'/blog?category='.$category->slug,
                'changefreq' => 'weekly',
                'priority' => '0.7',
                'lastmod' => optional($category->updated_at)->toAtomString(),
            ];
        }

        $posts = BlogPost::where('atelier_id', $atelierId)
            ->published()
            ->orderByDesc('published_at')
            ->get(['slug', 'updated_at', 'published_at']);

        foreach ($posts as $post) {
            $urls[] = [
                'loc' => $base.$shopPath.'/blog/'.$post->slug,
                'changefreq' => 'weekly',
                'priority' => '0.8',
                'lastmod' => optional($post->updated_at ?: $post->published_at)->toAtomString(),
            ];
        }

        if ($request->wantsJson() || $request->boolean('json')) {
            return response(['urls' => $urls, 'count' => count($urls)]);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.e($url['loc'])."</loc>\n";
            if (! empty($url['lastmod'])) {
                $xml .= '    <lastmod>'.e($url['lastmod'])."</lastmod>\n";
            }
            $xml .= '    <changefreq>'.e($url['changefreq'])."</changefreq>\n";
            $xml .= '    <priority>'.e($url['priority'])."</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
