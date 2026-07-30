<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Domain\Service;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR code en SVG, prêt à intégrer dans un PDF.
 *
 * Le SVG s'embarque dans un document dompdf sans dépendre d'imagick, et reste
 * net à toute taille — ce qu'exige un QR de certification que la douane doit
 * pouvoir scanner sur un document réimprimé.
 */
final class QrSvg
{
    public static function dataUri(string $content, int $size = 150): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($content));
    }
}
