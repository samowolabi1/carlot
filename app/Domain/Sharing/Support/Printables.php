<?php

namespace App\Domain\Sharing\Support;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Sharing\Actions\CreateShareLink;
use App\Domain\Sharing\Enums\SharePlatform;
use App\Domain\Support\PhoneNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use finfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Printables for the lot (TDD M6): a gate poster with a QR to the mini-site, and a sheet of
 * windscreen stickers, one QR per car. Every QR goes through a /c/{code} share link with
 * platform "qr", so scans show up in analytics.
 */
final class Printables
{
    public const SIZES = ['a4', 'a3'];

    public function __construct(private readonly CreateShareLink $links) {}

    public function poster(Lot $lot, string $size = 'a4'): string
    {
        $size = in_array($size, self::SIZES, true) ? $size : 'a4';
        $link = $this->links->run($lot, SharePlatform::Qr)->url();

        return Pdf::loadView('pdf.poster', [
            'lot' => $lot,
            'qr' => $this->dataUri(QrCode::png($link, 900, '#16181D')),
            'logo' => $this->logo($lot),
            'link' => $link,
            'site' => preg_replace('#^https?://#', '', route('lots.show', $lot)),
            'whatsapp' => $lot->whatsapp ? PhoneNumber::display($lot->whatsapp) : ($lot->phone ? PhoneNumber::display($lot->phone) : null),
            'brand' => $lot->brand_color ?: '#16302B',
            'scale' => $size === 'a3' ? 1.414 : 1,
        ])->setPaper($size)->setOption('isFontSubsettingEnabled', true)->output();
    }

    /** @param Collection<int, Vehicle> $vehicles */
    public function stickers(Lot $lot, Collection $vehicles): string
    {
        $stickers = $vehicles->map(fn (Vehicle $v) => [
            'title' => $v->title(),
            'price' => $v->formattedPrice(),
            'qr' => $this->dataUri(QrCode::png($this->links->run($v, SharePlatform::Qr)->url(), 420, '#16181D')),
        ]);

        return Pdf::loadView('pdf.stickers', ['lot' => $lot, 'rows' => $stickers->chunk(3), 'brand' => $lot->brand_color ?: '#16302B'])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();
    }

    private function dataUri(string $png): string
    {
        return 'data:image/png;base64,'.base64_encode($png);
    }

    /** The logo as a data URI (dompdf doesn't fetch remote images). */
    private function logo(Lot $lot): ?string
    {
        if (! $lot->logo_path) {
            return null;
        }

        try {
            $bytes = Storage::disk(config('lotlink.media_disk'))->get($lot->logo_path);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer((string) $bytes) ?: 'image/png';

            return $bytes && in_array($mime, ['image/png', 'image/jpeg'], true) ? "data:{$mime};base64,".base64_encode($bytes) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
