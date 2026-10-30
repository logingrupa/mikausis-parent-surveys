# Survey publishing controls and sharing

User authorized adding unpublish, improving the administrator survey action hierarchy, native sharing with a fallback, and shipping the finished changes to skola as soon as verified.

## Scope

- Add nullable surveys.unpublished_at with a forward migration. Keep publication date, slug, tenant, answers, detached contacts and historical question definitions intact.
- Add an authorized workflow action that closes and unpublishes a survey. Republish clears the visibility flag. Public forms, session endpoints and new submissions cannot use an unpublished survey.
- Expose the main actions in the list and editor. Use the survey title as the editor heading, distinguish primary actions from secondary actions and keep lifecycle controls visible.
- Replace the confusing public-link label. Use Web Share when supported and a copy-link fallback elsewhere. Share only the ordinary public survey URL, never a family invitation or authentication token.
- Obtain approval for the proposed new Latvian labels before putting them in the product. Draft the DNS-maintainer message in chat as requested.
- Verify tenant authorization, preservation, hidden public routes, reopening and sharing behavior, then capture the actual administrator UI at desktop and 390x844 light/dark in a private sandbox.
- Commit and push the reviewed application changes, deploy site 3474647 on skola 1273956, apply the additive migration with a consistent SQLite backup, and verify live without altering the real survey's publication state or data.

## Files

app/Models/Survey.php; app/Filament/Support/SurveyWorkflow.php; app/Filament/Resources/Surveys/SurveyResource.php; app/Filament/Resources/Surveys/Pages/EditSurvey.php; app/Http/Controllers/SurveyController.php; app/Http/Controllers/QuestionContactController.php; app/Providers/Filament/AdminPanelProvider.php; public/css/admin.css; public/js/survey-share.js; database/migrations/2026_10_31_000100_add_unpublished_at_to_surveys.php; tests/Feature/SurveyUnpublishTest.php; tests/Feature/AdminSurveyTest.php; tests/JavaScript/survey-share.test.mjs. Update an existing focused assertion only if it conflicts with the approved label change.

## Gates

1. Implement and run focused tests, formatting and syntax checks, all with SQLite :memory: for PHP.
2. Obtain one Codex review through T3. Resolve material findings in one fix round.
3. Complete one private browser verification and deliver screenshots.
4. Commit, push, deploy and verify the actual temporary production domain. Preserve all existing accounts, surveys, responses, contacts, keys and other server sites.

## Recovery

Keep the new migration additive. Back up the live SQLite database through its backup API before migration and preserve the matching production key. Never copy the development database to production. Abort on deployment or verification failure and preserve data for a controlled recovery.

## Verification

The Codex review found no concrete P1/P2 issues. Final checks passed: 632 PHP tests with 6,576 assertions using SQLite :memory:, 196 Node tests, scoped Pint and whitespace checks. Private browser checks passed for native share invocation, clipboard fallback, confirmation, public 404 after unpublishing and restoration of the same URL after republishing. The mobile list has four visible actions; the editor keeps Save prominent and puts secondary actions under Vairāk. Final screenshots were inspected at 390x844 light and dark preference and 1440x1000 desktop without horizontal overflow. Evidence uses the publishing-ux-qa-20261031-a1 prefix in ignored artifacts.
