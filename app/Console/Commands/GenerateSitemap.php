<?php

namespace App\Console\Commands;

use App\Domain\Seo\Sitemap;
use Illuminate\Console\Command;

/** Nightly at 03:00 (TDD scheduler): rebuild sitemap.xml. */
class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Rebuild sitemap.xml from live sellers, cars and landing pages';

    public function handle(): int
    {
        $this->info('Sitemap written with '.Sitemap::generate().' URLs.');

        return self::SUCCESS;
    }
}
