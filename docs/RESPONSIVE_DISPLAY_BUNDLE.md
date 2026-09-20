# Responsive Display manual placements

In **Direct Demand → Quick Monetize**, select a website, choose **Responsive Display**, paste one supported provider display tag and activate once. Horus creates four manual placements sharing that tag. This is a website-specific group: another website gets its own four identities.

Copy the four placement DIVs from the selected website's Quick Monetize result, **Inventory**, or the publisher's **Website details** page. Keep the permanent Horus Loader installed once. Place each DIV at its chosen location in the page or template, once per page. Install any or all four; absent DIVs do not auto-mount or request ads. Each installed unit is centered within its available container width.

Use the generated codes, rather than constructing them yourself: existing inventory can require a collision-safe suffix. Do not paste the provider script into each publisher position. Horus shares the reviewed tag configuration, loads the runtime once, and gives every GPT slot a unique DOM identity even when all four use the same GAM ad unit path. Provider tags needing isolation keep separate frame contexts.

Submitting Responsive Display again updates the same group. Selecting one group member in Quick Monetize's existing-placement option also updates the group's provider tag. An existing legacy Quick Responsive placement retains its identity and becomes manual when explicitly activated through this flow. Other presets remain on their existing paths; there is no automatic rollout to publishers.

Saving queues a production configuration; it does not prove live delivery or provider fill. The existing Traffic Gate, Click Guard, consent checks and emergency controls apply to all four. A blocked member rejects the whole activation transaction. No changes to protection policy, timed refresh or other ad formats are part of this feature.

Regression coverage: `DirectDemandQuickMonetizeTest`, `QuickMonetizeProviderAgnosticTest`, `QuickMonetizeDisplaySuiteCommandTest`, and `responsive-bundle.playwright.spec.js` (compiled loader, desktop/mobile Chromium and WebKit). Production static synchronization reports the public Traffic Gate and Click Guard flags without exposing challenge keys or credentials.
