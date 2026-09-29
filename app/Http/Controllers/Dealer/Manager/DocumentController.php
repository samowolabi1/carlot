<?php

namespace App\Http\Controllers\Dealer\Manager;

use App\Domain\LotManager\Actions\UpdateOrderDocument;
use App\Domain\LotManager\Enums\DocumentStatus;
use App\Domain\LotManager\Models\OrderDocument;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Papers and handover checklist on an order (TDD M19). */
class DocumentController extends Controller
{
    public function update(Request $request, Lot $lot, SalesOrder $order, OrderDocument $document, UpdateOrderDocument $update): RedirectResponse
    {
        Gate::authorize('changeStatus', $order);
        $data = $request->validate([
            'status' => ['required', Rule::enum(DocumentStatus::class)],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $update->run($document, $request->user(), DocumentStatus::from($data['status']), $request->file('file'));

        return back();
    }

    public function store(Request $request, Lot $lot, SalesOrder $order, UpdateOrderDocument $update): RedirectResponse
    {
        Gate::authorize('changeStatus', $order);
        $data = $request->validate(['label' => ['required', 'string', 'max:80'], 'mandatory' => ['boolean']]);

        $update->add($order, $data['label'], (bool) ($data['mandatory'] ?? false));

        return back();
    }

    public function destroy(Lot $lot, SalesOrder $order, OrderDocument $document, UpdateOrderDocument $update): RedirectResponse
    {
        Gate::authorize('changeStatus', $order);
        $update->remove($document);

        return back();
    }

    public function file(Lot $lot, SalesOrder $order, OrderDocument $document): StreamedResponse
    {
        Gate::authorize('view', $order);
        abort_if($document->file_path === null || ! Storage::disk(OrderDocument::DISK)->exists($document->file_path), 404);

        return Storage::disk(OrderDocument::DISK)->download($document->file_path, str($document->name())->slug().'.'.pathinfo($document->file_path, PATHINFO_EXTENSION));
    }
}
