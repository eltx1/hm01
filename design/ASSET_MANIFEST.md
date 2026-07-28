# Horus Media Design Asset Manifest

The approved brand package supplied by the owner is the only visual reference for the platform.

## Source package

`HorusMedia.net-Final-Website(1).zip`

## Approved image assets

| Source path | Intended platform use |
|---|---|
| `assets/images/horusmedia-logo-official.png` | Login, onboarding, statements, emails, high-visibility brand lockup |
| `assets/images/horusmedia-emblem.png` | General emblem use and large branded empty states |
| `assets/images/horusmedia-emblem-header.png` | Sidebar, navigation header, compact application shell |
| `assets/images/horusmedia-emblem-hero.png` | Login hero, onboarding hero, branded dashboard welcome area |
| `assets/images/horusmedia-social.jpg` | Social previews and public sharing cards |
| `favicon.png` | Browser favicon |
| `icon-192.png` | PWA/mobile icon |
| `icon-512.png` | PWA/high-resolution icon |

## Approved visual reference files

- `index.html`
- `assets/css/style.css`
- `assets/js/main.js`
- `_headers`
- `site.webmanifest`

## Mandatory implementation behavior

1. Copy the approved image files into `public/assets/brand/` during application scaffolding.
2. Keep the original transparent PNG files unchanged.
3. Generate optimized derivatives only as additional files; never overwrite originals.
4. Use the colors and component language in `docs/BRAND_SYSTEM.md` and `design/horus-brand-tokens.css`.
5. The admin, publisher, and advertiser experiences must share one theme and one component library.
6. Do not install a generic admin-template theme that visually overrides Horus Media branding.
7. The final release must contain the approved brand assets and favicon/PWA assets.

## Target structure

```text
public/assets/brand/
├── horusmedia-logo-official.png
├── horusmedia-emblem.png
├── horusmedia-emblem-header.png
├── horusmedia-emblem-hero.png
├── horusmedia-social.jpg
├── favicon.png
├── icon-192.png
└── icon-512.png
```

## Quality checks

- Transparent backgrounds remain transparent.
- No unexpected black or white box is added.
- Aspect ratio is preserved.
- Small header logo remains sharp.
- Large login logo is not upscaled beyond useful quality.
- All logo images include appropriate alternative text unless decorative.
- The same official brand is visible across web UI, statements, reports, emails, favicon, and PWA surfaces.
