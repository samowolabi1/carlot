<?php

namespace App\Http\Controllers\Dealer\Manager;

use App\Domain\LotManager\Models\FollowUpTask;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Follow-up tasks: done, or pushed to tomorrow. */
class TaskController extends Controller
{
    public function update(Request $request, Lot $lot, FollowUpTask $task): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:done,tomorrow']]);

        if ($data['action'] === 'done') {
            $task->forceFill(['done_at' => now()])->save();

            return back()->with('success', 'Follow-up done.');
        }

        $task->forceFill(['due_at' => $task->due_at->copy()->addDay(), 'reminded_at' => null])->save();

        return back()->with('success', 'Moved to tomorrow.');
    }
}
