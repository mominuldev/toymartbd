# Smart Junior — store icon / favicon

Built from the main logo in Figma (`2b6PVOOapPbcaKYIdHWZup`, node `291:3395`, inside `291:3396`
"Logo And Branding color"). The full wordmark is too wide to read at 16 px, so the icon uses the
logo's own letterforms, with their path data copied unchanged from the Figma SVG export.

**Figma source:** component `Favicon / Smart Junior` (node `296:3302`), in the `Favicon` frame
(`296:3304`) beside the main logo on Foundations. The S is a clone of the logo's own S, filled
with `purple/800`; the tile is `sunshine/400`. Export changes from there, not from these files.

| File | What it is |
|---|---|
| `smart-junior-icon-s.svg` / `-1024.png` | **Recommended.** Purple `S` (#581C9C) on a yellow (#FDC403) rounded tile. Still reads at 16 px and stands out on both light and dark browser tabs. |
| `smart-junior-icon-s.ico` | Same icon as a 16/32/48 ICO, for anything that needs a static file. |
| `smart-junior-icon-sj.svg` / `-1024.png` | Alternative. Purple `S` and orange `J` (#FD5E03) on a white tile. Gets muddy at 16 px and disappears on a white tab. |

## How it reaches the storefront

No static favicon ships with the storefront (`lib/seo/favicon.ts` in the storefront repo). The icon is
a **setting**: upload `smart-junior-icon-s-1024.png` in the admin at Store settings › Site & branding ›
Store icon. The upload must be square and at least 512 × 512. `RenderStoreFavicons` then renders the
32/180/192/512 PNGs, and `/favicon.ico` redirects to the 32 px one.
