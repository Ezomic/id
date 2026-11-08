<?php

declare(strict_types=1);

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class QrCodeRenderer
{
    /**
     * An image data URI rather than inline markup, so the page never has to
     * trust the SVG as HTML.
     */
    public function dataUri(string $content): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(240, 2), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($content));
    }
}
