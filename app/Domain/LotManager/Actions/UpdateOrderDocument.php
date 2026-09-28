<?php

namespace App\Domain\LotManager\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\LotManager\Enums\DocumentStatus;
use App\Domain\LotManager\Enums\DocumentType;
use App\Domain\LotManager\Models\OrderDocument;
use App\Domain\LotManager\Models\SalesOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateOrderDocument
{
    /** Marks a paper received or handed over, with an optional scan kept on the private disk. */
    public function run(OrderDocument $document, User $user, DocumentStatus $status, ?UploadedFile $file = null): OrderDocument
    {
        if ($file !== null) {
            $old = $document->file_path;
            $document->file_path = $file->storeAs(
                'order-documents/'.$document->sales_order_id,
                Str::lower((string) Str::ulid()).'.'.strtolower($file->getClientOriginalExtension() ?: 'pdf'),
                ['disk' => OrderDocument::DISK],
            ) ?: null;
            if ($old) {
                Storage::disk(OrderDocument::DISK)->delete($old);
            }
        }

        $document->status = $status;
        $document->received_at = $status === DocumentStatus::Pending ? null : ($document->received_at ?? now());
        $document->handed_over_at = $status === DocumentStatus::HandedOver ? ($document->handed_over_at ?? now()) : null;
        $document->save();

        AuditLog::record('order.document', $document, ['item' => $document->name(), 'status' => $status->value], $user, $document->lot_id);

        return $document;
    }

    /** An extra line on the checklist, e.g. "Service book". */
    public function add(SalesOrder $order, string $label, bool $mandatory): OrderDocument
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['label' => 'This order is closed.']);
        }

        return OrderDocument::withoutGlobalScopes()->create([
            'lot_id' => $order->lot_id,
            'sales_order_id' => $order->id,
            'type' => DocumentType::Other,
            'label' => trim($label),
            'mandatory' => $mandatory,
            'status' => DocumentStatus::Pending,
        ]);
    }

    /** Extra lines can be removed while nothing has come in for them. */
    public function remove(OrderDocument $document): void
    {
        if ($document->type !== DocumentType::Other || $document->status !== DocumentStatus::Pending) {
            throw ValidationException::withMessages(['document' => 'Only an extra item that hasn\'t come in can be removed.']);
        }

        $document->delete();
    }
}
