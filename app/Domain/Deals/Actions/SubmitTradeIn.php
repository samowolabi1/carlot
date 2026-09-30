<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Deals\Enums\TradeInCondition;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Deals\Notifications\DealAlert;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Actions\CaptureLead;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Name;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class SubmitTradeIn
{
    public function __construct(private readonly CaptureLead $capture, private readonly DealTimeline $timeline) {}

    /**
     * A buyer asks a lot to value their car (TDD M12). Photos are re-encoded (which strips
     * EXIF GPS) and kept on the private disk; only the lot and the buyer can see them.
     *
     * @param  array{make_id: int, vehicle_model_id?: ?int, model_name?: ?string, year: int, mileage_km: int, condition: string, notes?: ?string}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function run(Lot $lot, User $customer, array $data, array $photos, ?Vehicle $towards = null): TradeIn
    {
        if ($customer->hasLotRole($lot)) {
            throw ValidationException::withMessages(['make_id' => 'You work at this lot.']);
        }
        if (! $lot->takesTradeIns()) {
            throw ValidationException::withMessages(['make_id' => "{$lot->name} isn't taking trade-ins right now."]);
        }

        $ulid = Str::lower((string) Str::ulid());
        $paths = array_map(fn (UploadedFile $photo) => $this->store($ulid, $photo), array_slice($photos, 0, TradeIn::MAX_PHOTOS));

        $lead = $this->capture->run($lot, $customer, LeadSource::TradeIn, $towards);

        $tradeIn = TradeIn::withoutGlobalScopes()->make([
            'lot_id' => $lot->id,
            'lead_id' => $lead->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $towards?->id,
            'make_id' => $data['make_id'],
            'vehicle_model_id' => $data['vehicle_model_id'] ?? null,
            'model_name' => filled($data['vehicle_model_id'] ?? null) ? null : ($data['model_name'] ?? null),
            'year' => $data['year'],
            'mileage_km' => $data['mileage_km'],
            'condition' => TradeInCondition::from($data['condition']),
            'notes' => $data['notes'] ?? null,
            'photos' => $paths,
            'currency' => (string) config('lotlink.currency', 'NGN'),
            'status' => TradeInStatus::Submitted,
        ]);
        $tradeIn->ulid = $ulid; // the photo folder is named after it
        $tradeIn->save();

        $title = $tradeIn->load(['make', 'model'])->title();
        $this->timeline->post($lead, "Trade-in sent for valuation: {$title}, ".number_format($tradeIn->mileage_km).' km, '.count($paths).' photos');

        Notification::send($lot->members()->get(), new DealAlert(
            'trade_in',
            Name::short($customer->name)." wants a valuation for their {$title}".($towards ? ' towards the '.$towards->title() : '').'.',
            DealLinks::lot($lot, 'trade-ins'),
        ));

        return $tradeIn;
    }

    private function store(string $ulid, UploadedFile $photo): string
    {
        $image = (new ImageManager(new Driver, autoOrientation: true))->read($photo->getRealPath())->scaleDown(width: 1600);
        $path = "trade-ins/{$ulid}/".Str::lower((string) Str::ulid()).'.webp';

        Storage::disk(TradeIn::DISK)->put($path, (string) $image->toWebp(quality: 80));

        return $path;
    }
}
