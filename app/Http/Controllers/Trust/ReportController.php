<?php

namespace App\Http\Controllers\Trust;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Actions\SubmitReport;
use App\Domain\Trust\Enums\ReportReason;
use App\Domain\Trust\Models\Report;
use App\Domain\Trust\Models\Review;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** POST /reports (TDD M14): report a listing, lot, review or chat message. */
class ReportController extends Controller
{
    public function store(Request $request, SubmitReport $submit): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Report::KINDS))],
            'id' => ['required', 'string', 'max:40'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'details' => ['nullable', 'string', 'max:500'],
        ], ['reason.required' => 'Choose what is wrong.']);

        $subject = $this->find($request, $data['kind'], $data['id']);
        $submit->run($request->user(), $subject, ReportReason::from($data['reason']), $data['details'] ?? null);

        return back()->with('success', 'Thanks for telling us. Our team will take a look, usually within a day.');
    }

    /** Only things the reporter can actually see: marketplace cars and lots, visible reviews, their own chats. */
    private function find(Request $request, string $kind, string $id): Model
    {
        return match ($kind) {
            'vehicle' => Vehicle::query()->marketplace()->where('vehicles.ulid', strtolower($id))->firstOrFail(),
            'lot' => Lot::where('slug', $id)->firstOrFail(),
            'review' => Review::withoutGlobalScopes()->where('ulid', strtolower($id))->firstOrFail(),
            default => tap(Message::whereKey((int) $id)->with('conversation')->firstOrFail(), function (Message $m) use ($request): void {
                abort_unless($request->user()->can('view', $m->conversation), 404);
            }),
        };
    }
}
