import { isIP } from 'node:net';

// The shopper's IP as this Next server saw it, for forwarding to Laravel as X-Forwarded-For so
// its per-IP rate limits (OTP, tracking, reviews, the storefront group) key on the shopper rather
// than on this one server. Laravel honours the header only from TRUSTED_PROXIES
// (config/trustedproxy.php), so a value we forward cannot widen anyone's access — but it must still
// be the address OUR edge saw, not one the browser made up:
//
// - `X-Real-IP` first: the reverse proxy in front of Next (nginx `proxy_set_header X-Real-IP
//   $remote_addr`) overwrites whatever the client sent.
// - otherwise the RIGHTMOST `X-Forwarded-For` entry: a proxy appends the peer it saw, so earlier
//   entries are client-supplied and untrusted.
//
// Anything that is not a literal IPv4/IPv6 address is dropped (no header is forwarded at all).

function literalIp(value: string | null | undefined): string | null {
    const ip = (value ?? '').trim().replace(/^\[(.*)\]$/, '$1');
    return ip !== '' && isIP(ip) !== 0 ? ip : null;
}

export function clientIpFrom(headers: Pick<Headers, 'get'>): string | null {
    const real = literalIp(headers.get('x-real-ip'));
    if (real) return real;
    const chain = (headers.get('x-forwarded-for') ?? '').split(',');
    return literalIp(chain[chain.length - 1]);
}
