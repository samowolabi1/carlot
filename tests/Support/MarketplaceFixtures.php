<?php

namespace Tests\Support;

use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\Artisan;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;

/** Builds a small marketplace and, for Meilisearch runs, indexes it. */
trait MarketplaceFixtures
{
    protected function useSearchEngine(string $engine): void
    {
        if ($engine === 'meilisearch') {
            $host = env('MEILISEARCH_TEST_HOST');

            if (! $host) {
                $this->markTestSkipped('Set MEILISEARCH_TEST_HOST to run the Meilisearch engine tests.');
            }

            config([
                'scout.driver' => 'meilisearch',
                'scout.prefix' => 'test_'.bin2hex(random_bytes(4)).'_',
                'scout.meilisearch.host' => $host,
                'scout.meilisearch.key' => env('MEILISEARCH_TEST_KEY'),
            ]);
            Artisan::call('scout:sync-index-settings');
            $this->waitForMeilisearch();
        } else {
            config(['scout.driver' => 'null']);
        }
    }

    protected function reindex(): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }

        $this->waitForMeilisearch();
    }

    protected function waitForMeilisearch(): void
    {
        $client = app(Client::class);

        for ($i = 0; $i < 100; $i++) {
            $pending = $client->getTasks((new TasksQuery)->setStatuses(['enqueued', 'processing']))->getResults();

            if ($pending === []) {
                return;
            }

            usleep(50_000);
        }
    }

    protected function tearDownMeilisearch(): void
    {
        if (config('scout.driver') === 'meilisearch') {
            $client = app(Client::class);
            $client->deleteIndex(config('scout.prefix').'vehicles');
        }
    }

    /** @param array<string, mixed> $attributes */
    protected function car(Lot $lot, string $make, string $model, array $attributes = []): Vehicle
    {
        $makeModel = Make::firstOrCreate(['slug' => str($make)->slug()->toString()], ['name' => $make]);
        $vehicleModel = VehicleModel::firstOrCreate(
            ['make_id' => $makeModel->id, 'slug' => str($model)->slug()->toString()],
            ['name' => $model, 'approved_at' => now()],
        );

        return Vehicle::factory()->withPhoto()->available()->create([
            'lot_id' => $lot->id,
            'vehicle_model_id' => $vehicleModel->id,
            ...$attributes,
        ])->refresh();
    }
}
