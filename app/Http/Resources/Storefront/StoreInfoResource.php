<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * `GET /storefront/store-info` — the shop's public contact and legal details, entered once in
 * Admin → Settings → Store → Business (the same values invoices print). A value the owner has
 * not set is null, and the storefront then shows its marked sample instead (launch item F4).
 * Site & branding (Store settings) adds the site name, tagline, description, logos, share image and
 * favicons — all nullable. Public data only: nothing here is a secret or personal.
 *
 * @property array{name_en: string, name_bn: string, hotline: string|null, whatsapp: string|null, messenger: string|null, email: string|null, address_en: string|null, address_bn: string|null, trade_licence: string|null, bin: string|null, site_name_en: string|null, site_name_bn: string|null, tagline_en: string|null, tagline_bn: string|null, description_en: string|null, description_bn: string|null, logo: array{url: string, width: int, height: int}|null, logo_dark: array{url: string, width: int, height: int}|null, share_image: array{url: string, width: int, height: int}|null, icons: array{png32: string, png180: string, png192: string, png512: string}|null} $resource
 */
final class StoreInfoResource extends ApiResource
{
    /**
     * @return array{name_en: string, name_bn: string, hotline: string|null, hotline_tel: string|null, whatsapp: string|null, whatsapp_display: string|null, messenger: string|null, email: string|null, address_en: string|null, address_bn: string|null, trade_licence: string|null, bin: string|null, site_name_en: string|null, site_name_bn: string|null, tagline_en: string|null, tagline_bn: string|null, description_en: string|null, description_bn: string|null, logo: array{url: string, width: int, height: int}|null, logo_dark: array{url: string, width: int, height: int}|null, share_image: array{url: string, width: int, height: int}|null, icons: array{png32: string, png180: string, png192: string, png512: string}|null}
     */
    public function toArray(Request $request): array
    {
        $info = $this->resource;
        $hotline = $info['hotline'];
        $whatsapp = $info['whatsapp'];

        return [
            'name_en' => $info['name_en'],
            'name_bn' => $info['name_bn'],
            /** As the owner typed it (e.g. "09610-123456"). Western digits. */
            'hotline' => $hotline,
            /** `tel:` link target, `+880…` E.164 form; null when no hotline is set. */
            'hotline_tel' => self::tel($hotline),
            /** E.164 (`+8801…`), for a wa.me link. */
            'whatsapp' => $whatsapp,
            /** Local form for display, `01712-345678`. */
            'whatsapp_display' => self::localMobile($whatsapp),
            /** The Facebook Page username or numeric id, for a `https://m.me/{messenger}` link. */
            'messenger' => $info['messenger'],
            'email' => $info['email'],
            'address_en' => $info['address_en'],
            'address_bn' => $info['address_bn'],
            'trade_licence' => $info['trade_licence'],
            'bin' => $info['bin'],
            /**
             * Site & branding (additive, P10). The storefront falls back to `name_en` while blank.
             *
             * @var string|null
             */
            'site_name_en' => $info['site_name_en'],
            /** @var string|null */
            'site_name_bn' => $info['site_name_bn'],
            /** @var string|null */
            'tagline_en' => $info['tagline_en'],
            /** @var string|null */
            'tagline_bn' => $info['tagline_bn'],
            /**
             * The search and social description (meta description, Open Graph).
             *
             * @var string|null
             */
            'description_en' => $info['description_en'],
            /** @var string|null */
            'description_bn' => $info['description_bn'],
            /**
             * Logo for light backgrounds: a ~480 px rendition with its pixel size.
             *
             * @var array{url: string, width: int, height: int}|null
             */
            'logo' => $info['logo'],
            /**
             * Logo for dark backgrounds: a ~480 px rendition with its pixel size.
             *
             * @var array{url: string, width: int, height: int}|null
             */
            'logo_dark' => $info['logo_dark'],
            /**
             * The default Open Graph image: the 1200 px rendition (smaller if the source is).
             *
             * @var array{url: string, width: int, height: int}|null
             */
            'share_image' => $info['share_image'],
            /**
             * Absolute URLs of the PNG favicons rendered from the store icon; null until rendered.
             *
             * @var array{png32: string, png180: string, png192: string, png512: string}|null
             */
            'icons' => $info['icons'],
        ];
    }

    private static function tel(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '880')) {
            return 'tel:+'.$digits;
        }

        return 'tel:+88'.$digits;
    }

    private static function localMobile(?string $e164): ?string
    {
        if ($e164 === null) {
            return null;
        }
        $local = '0'.substr((string) preg_replace('/\D+/', '', $e164), 3);

        return strlen($local) === 11 ? substr($local, 0, 5).'-'.substr($local, 5) : $local;
    }
}
