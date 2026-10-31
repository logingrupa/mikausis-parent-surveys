# Right-align survey actions and keep one search

Disable Filament global search through the panel configuration. Keep the survey table search. Right-align the existing survey card actions on desktop and retain the two-column mobile layout.

Files: app/Providers/Filament/AdminPanelProvider.php; public/css/admin.css; this plan; SUMMARY.md in this directory.

Run the existing AdminSurveyTest suite with SQLite :memory:, scoped Pint and whitespace checks. Verify real table filtering, absence of global search, right alignment, button sizes, dropdown behavior and no overflow in an isolated synthetic sandbox. Capture and inspect desktop and true 390x844 light and dark screenshots, then show them. Obtain one scoped Codex review. Commit, push and deploy to the existing Forge skola site. Verify live revision, search configuration, CSS, HTTP health and unchanged production data fingerprints. Clean up only owned QA state.
