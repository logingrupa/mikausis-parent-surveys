# Clear survey actions on the administrator dashboard

Make each existing dashboard survey entry visibly actionable. Reuse Pārvaldīt as the primary button, retain Skatīt rezultātus as a secondary button and underline the title link with a direction icon. Preserve normal link behavior and tenant-scoped URLs. Keep the row flat with its existing divider, use 44px action targets and align the two buttons on mobile. Add no new product copy.

Files: resources/views/filament/pages/dashboard.blade.php; public/css/admin.css; this plan.

Run the existing AdminSurveyTest suite with forced SQLite :memory: and whitespace checks. Obtain one scoped Codex review. Verify actual link clicks, keyboard focus, long titles and desktop plus 390x844 light and dark preference in a private synthetic sandbox. Deliver screenshots, then commit, push and deploy through the existing Forge script. Verify the live revision, CSS and public HTTP status while preserving production data fingerprints. Clean up owned private state.
