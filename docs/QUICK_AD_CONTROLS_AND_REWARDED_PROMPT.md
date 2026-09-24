# Website ad controls and rewarded prompt

## Admin workflow

Open **Websites → Manage ads** on the website row, **Site 360 → Manage website ads**, or **Quick Monetize → Manage website ads**. Choose a website to see its Quick Monetize placements as cards with format, page position and state.

- **Pause ad** stops the selected placement; **Resume ad** re-enables it after checking its current renderer and provider availability.
- **Remove → Remove from this website** disables the selected placement and moves it into **Removed ads**. This is reversible; IDs, settings, provider mappings and financial history remain intact.
- **Restore paused** brings a removed placement back in paused state. Resume it when ready.
- Each action is scoped to the selected site and placement. Shared provider accounts, tags, other placements and other websites are not edited. Manually created placements are included only if connected through Quick Monetize.
- Changes publish through the existing static configuration outbox. Pause/removal use urgent priority. The screen shows the latest delivery status. Visitors receive the change after CDN delivery and their next page load; no new publisher installation code is needed.
- Staff require `demand.manage`; publisher users cannot call these admin routes. Mutations use existing inventory auditing, site-first transaction locks and rollback on invalid resume. Repeated requests for the same state do not create new versions.

## Rewarded message

GPT and VAST use the same build-time embedded prompt helper, with no additional browser request. The neutral white card includes a 44 × 44 px X, a separate decline button and explicit disclosure that watching is optional and closing does not block content. Clicking either dismissal control never opts into the ad or grants a reward. Existing trusted-click activation, provider callbacks, cooldowns, recovery timers and reward rules remain in their independent runtimes.

Language selection considers `navigator.languages`, then `navigator.language`, the page language and finally English. Supported languages: English, Arabic (RTL), French, Spanish, German, Portuguese, Turkish and Indonesian. Existing custom non-reading VAST title/copy/button text is preserved; the mandatory disclosure and close controls are localized. Element-scoped styles protect these controls from publisher CSS without styling publisher content or other ads. Keyboard dismissal and focus restoration remain supported; Tab includes the new X.

Google references reviewed:
- https://support.google.com/admanager/answer/7496282?hl=en
- https://developers.google.com/publisher-tag/samples/display-rewarded-ad

These UI changes support optional participation and clear dismissal. They are not a certification of inventory or reward-policy compliance; any custom reward must still be accurately described and delivered by its existing integration.

## Verification

- `npm run test:browser`: 239 passed, 0 failed locally.
- PHP feature coverage: per-site isolation, top/bottom preservation, urgent publication, idempotence, reversible removal, provider validation/rollback, authorization and read-only browsing.
- Chromium/WebKit, desktop/mobile: existing ad regression matrix plus X dismissal, hostile publisher CSS, browser-language precedence/fallback, RTL, keyboard focus and short landscape viewport.
- Backend and browser matrices, production build and deploy must pass on the exact PR/release SHA before release is considered complete. See the PR checks for final output.

## Changed files

- `app/Http/Controllers/Admin/QuickAdManagementController.php`
- `app/Services/Demand/QuickAdManagementService.php`
- `routes/direct-demand.php`
- `resources/views/admin/demand/manage-ads.blade.php`
- `resources/views/admin/demand/partials/quick-ad-card.blade.php`
- `resources/views/admin/demand/quick.blade.php`
- `resources/views/admin/sites/index.blade.php`
- `resources/views/publisher/sites/show.blade.php`
- `resources/css/components.css`
- `resources/js/ads/rewarded-prompt.js`
- `public/assets/hm-gpt-direct.js`
- `public/assets/hm-video-direct.js`
- `scripts/build-rewarded-prompt.mjs`
- `scripts/build-loader.mjs`
- `tests/Feature/QuickAdManagementTest.php`
- `tests/Browser/hm-direct-trusted-runtimes.test.js`
- `tests/Browser/quick-input-mode.playwright.spec.js`
- `tests/Browser/rewarded-reading.playwright.spec.js`
- `docs/QUICK_AD_CONTROLS_AND_REWARDED_PROMPT.md`
