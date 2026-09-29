<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Seo\Sitemap;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/** sitemap.xml and robots.txt (TDD M18). */
class SeoController extends Controller
{
    public function sitemap(): Response
    {
        return response(Sitemap::contents(), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(): Response
    {
        $lines = app()->isProduction()
            ? ['User-agent: *', 'Disallow: /dealer', 'Disallow: /admin', 'Disallow: /bookings', 'Disallow: /location', 'Disallow: /o/', 'Disallow: /c/', '', 'Sitemap: '.url('/sitemap.xml')]
            : ['User-agent: *', 'Disallow: /']; // keep test and staging sites out of search

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
