# Horus Media Brand System

## Status

This document is a binding product-design specification for the Horus Media white-label ad network platform.

The complete platform must use the same approved Horus Media logo, visual language, color system, spacing character, gradients, glass effects, borders, shadows, and premium dark interface style as the main website package supplied by the owner.

The platform must look like one product family with `horusmedia.net`, not like a generic Laravel admin template.

## Official brand assets

Approved source package:

- `HorusMedia.net-Final-Website`
- Official full logo: `assets/images/horusmedia-logo-official.png`
- Main emblem: `assets/images/horusmedia-emblem.png`
- Header emblem: `assets/images/horusmedia-emblem-header.png`
- Hero emblem: `assets/images/horusmedia-emblem-hero.png`
- Social image: `assets/images/horusmedia-social.jpg`
- Favicon: `favicon.png`

The owner-provided logo must be used without redrawing, recoloring, simplifying, stretching, cropping, or replacing it with an unrelated icon.

## Core palette

```css
--hm-ink: #050816;
--hm-night: #050b1e;
--hm-night-2: #07132e;
--hm-navy: #0a2153;
--hm-royal: #12499d;
--hm-blue: #1c63cf;
--hm-gold: #f1b733;
--hm-gold-highlight: #ffd66b;
--hm-gold-soft: #ffe7a9;
--hm-white: #f6f8ff;
--hm-muted: #9da9c2;
--hm-muted-2: #71809e;
```

## Supporting tokens

```css
--hm-line: rgba(255,255,255,.09);
--hm-line-gold: rgba(241,183,51,.22);
--hm-glass: rgba(8,18,43,.62);
--hm-radius-sm: 12px;
--hm-radius-md: 18px;
--hm-radius-lg: 26px;
--hm-radius-pill: 999px;
--hm-shadow: 0 28px 90px rgba(0,0,0,.38);
--hm-gold-shadow: 0 17px 45px rgba(241,183,51,.15);
```

## Typography

Primary interface stack:

```css
font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
```

Rules:

- Headings are bold, compact, premium, and high contrast.
- Body text uses comfortable line-height and muted blue-gray secondary color.
- Numbers in revenue and performance cards must be prominent and easy to scan.
- Gold is used for emphasis, active states, totals, and premium actions; it must not dominate every element.
- Avoid generic blue SaaS styling.

## Main visual language

The application must preserve these characteristics from the public website:

- Deep navy and near-black backgrounds.
- Royal-blue radial glows.
- Restrained gold lighting and borders.
- Glass panels with subtle blur.
- Thin translucent borders.
- Large rounded cards.
- Premium gradients instead of flat primary colors.
- Soft shadows, never heavy gray drop shadows.
- Grid and signal motifs may appear in empty states, login screens, and major dashboard headers.
- Motion must be subtle and must never slow down reporting or management workflows.

## Platform shell

### Desktop

- Fixed or sticky dark sidebar.
- Header with compact Horus emblem and `Horus Media` brand lockup.
- Main content uses large premium cards and clear information hierarchy.
- Active sidebar item uses royal-blue and gold accents.
- Financial totals and primary actions use gold emphasis.
- Destructive actions remain clearly red and must not be styled as gold.

### Mobile

- Collapsible navigation drawer.
- No horizontal overflow.
- Data tables switch to cards or controlled scrolling.
- Key revenue metrics remain visible without excessive vertical space.
- The logo must remain readable at small sizes.

## Logo usage

Use the official assets as follows:

- Login and onboarding: full official logo.
- Desktop sidebar: emblem plus Horus Media text.
- Collapsed sidebar: emblem only.
- Mobile header: header emblem.
- Browser icon and PWA: supplied favicon and icons.
- Reports and statements: full logo on first page; compact emblem on following pages.
- Email templates: full logo with dark-background-safe spacing.

Minimum clear space around the emblem should equal at least 12% of its displayed width.

Do not:

- Add a white box behind the transparent PNG.
- Change gold to yellow.
- Change navy to black-only.
- Distort the emblem.
- Add an unrelated bird icon.
- Replace the wordmark with generic text when the official full logo is appropriate.

## Component rules

### Cards

- Background: dark translucent navy.
- Border: `--hm-line`, with optional gold border for selected or premium states.
- Radius: 18–26px.
- Hover: small translate and border/glow change only.

### Buttons

Primary button:

```css
background: linear-gradient(115deg, #ffe495, #f1b733 56%, #cf8b13);
color: #071127;
```

Secondary button:

- Transparent dark background.
- Subtle light or gold border.
- White or muted text.

### Inputs

- Dark navy field background.
- Clear focus ring using royal blue plus a subtle gold accent.
- Labels always visible.
- Validation errors use red, not gold.

### Tables

- Dark headers.
- Clear row separators.
- Sticky headers where useful.
- Revenue columns right-aligned.
- Status badges use a controlled semantic palette.

### Charts

- Dark plotting background.
- Gold is the primary series.
- Royal blue is the secondary series.
- Additional series use accessible variations, not random colors.
- Grid lines remain subtle.

## Required platform pages using this system

- Login and password reset.
- Horus Media administrator dashboard.
- Publisher dashboard.
- Advertiser dashboard.
- Publisher onboarding.
- Site management.
- GAM connection management.
- Inventory and placements.
- Prebid configuration.
- Native demand connectors.
- Direct campaigns.
- Reports and reconciliation.
- Revenue shares and statements.
- Payments.
- Support.
- System settings.
- Error and maintenance pages.

## Implementation rule

Create one reusable theme layer. Do not copy inconsistent colors into individual screens.

Preferred structure:

```text
resources/css/brand-tokens.css
resources/css/components.css
resources/css/app.css
resources/views/components/brand/
resources/views/layouts/
public/assets/brand/
```

Every future UI task must read this file and the supplied website CSS before implementation.
