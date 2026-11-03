# A4 report export

Make the existing standalone HTML report the primary print/PDF export. Open it in the current tab with a full navigation, retaining A4 print styles, embedded assets, access controls and privacy suppression. Keep CSV and offline HTML downloads as secondary actions. Reuse existing Latvian labels.

Files: resources/views/filament/resources/surveys/pages/survey-report.blade.php, public/js/report-print.js, tests/Feature/AdminReportTest.php, tests/JavaScript/report-print.test.mjs.

Remove animation-frame dependence from automatic printing. Wait for document load, fonts and image decoding. If the user already opened print manually, do not open another automatic dialog.

Run scoped PHP tests with SQLite :memory: and the print runtime tests. One reviewer, then one verifier using synthetic data and an isolated browser. Verify the actual export click, populated A4 PDF pages, manual print, downloads, and 390 px light/dark screenshots. Keep real local and production data unchanged.
