# Smart Junior — store icon / favicon

The rocket-and-sun symbol the owner drew for the favicon (Figma `2b6PVOOapPbcaKYIdHWZup`, frame
`Favicon` `298:58680`). It replaces the earlier "S" icon (2026-11-26). The component
`Favicon / Smart Junior` (`296:3302`, in the `Favicon` frame `296:3304` beside the main logo) uses
the dark artwork, and its 180/64/32/16 previews and browser-tab mock are instances of it. Make
changes in Figma first, then re-export these files.

| File | What it is |
|---|---|
| `smart-junior-favicon-dark-513.png` | **The store icon.** The symbol on its dark square (`Artboard 4-8 1`). It is opaque, so it suits the 180 px Apple touch icon, and it reads on both light and dark browser tabs. |
| `smart-junior-favicon.ico` | The same artwork as a 16/32/48 ICO, for anything that needs a static file. |
| `smart-junior-favicon-transparent-513.png` | Alternative, no background (`Artboard 3-8 1`). Its yellow rays fade on a white tab at 16 px. |

The artwork is a raster at 513 × 513, just above the 512 minimum the upload requires. Ask for a
vector or a 1024 px export if a larger size is ever needed.

## How it reaches the storefront

No static favicon ships with the storefront (`lib/seo/favicon.ts` in the storefront repo). The icon is
a **setting**: upload `smart-junior-favicon-dark-513.png` in the admin at Store settings › Site &
branding › Store icon. The upload must be square and at least 512 × 512. `RenderStoreFavicons` then
renders the 32/180/192/512 PNGs, and `/favicon.ico` redirects to the 32 px one.
