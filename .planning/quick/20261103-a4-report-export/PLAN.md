# A4 report export

Make the existing standalone HTML report the primary print/PDF export. Open it in the current tab with a full navigation, retaining A4 print styles, embedded assets, access controls and privacy suppression. Keep CSV and offline HTML downloads as secondary actions. Reuse existing Latvian labels.

Files: resources/views/filament/resources/surveys/pages/survey-report.blade.php, public/js/report-print.js, tests/Feature/AdminReportTest.php, tests/JavaScript/report-print.test.mjs.

Remove animation-frame dependence from automatic printing. Wait for document load, fonts and image decoding. If the user already opened print manually, do not open another automatic dialog.

Run scoped PHP tests with SQLite :memory: and the print runtime tests. One reviewer, then one verifier using synthetic data and an isolated browser. Verify the actual export click, populated A4 PDF pages, manual print, downloads, and 390 px light/dark screenshots. Keep real local and production data unchanged.

## Approved release

The owner approved deployment on 2026-11-03. Push the reviewed application commit 85eee12 and this release record to origin/main. Deploy through the existing Forge script on skola, server 1273956, site 3474647, at /home/mikausis/aptauja.mikausis.lv. Public verification target: https://aptauja.kingdom.lv.

The production working tree is clean at 6b7fa8f. Save a read-only baseline of the environment hash and eight domain-table fingerprints before deployment. The existing deployment script creates and verifies a SQLite backup, installs locked dependencies, runs migrations and rebuilds caches, then reloads PHP-FPM and leaves maintenance mode. This release changes no migrations, configuration or dependencies.

One scoped Codex release review checks this deployment plan and the six-file commit. Existing code review, browser QA and test evidence are accepted without repeating them. Verify the remote commit, deployed file hashes, HTTP health, public script and unchanged domain data after deployment. Production currently has zero responses, so the authenticated report export cannot contain real results until the existing five-response threshold is met. Do not create production test data or log in as a real user.

If deployment fails, inspect its specific log and maintenance state. Restore service using the previous code commit if needed; do not restore or rewrite survey data. Record the release outcome in ignored artifacts, leaving the reviewed application commit unchanged.
