<?php

namespace App\Domain\Advertising\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Advertising\Enums\AdStatus;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Advertising\Notifications\AdSubmitted;
use Illuminate\Support\Facades\Notification;

/** Runs from FulfilPayment once the advert is paid: it waits for an admin to check it. */
class SubmitAdCampaign
{
    public function run(AdCampaign $campaign): AdCampaign
    {
        if ($campaign->status !== AdStatus::Draft) {
            return $campaign;
        }

        $campaign->forceFill(['status' => AdStatus::InReview])->save();
        Notification::send(User::query()->adminsFor(AdminArea::Moderation)->get(), new AdSubmitted($campaign));

        return $campaign;
    }
}
