# Deploy Mikausis surveys to skola

User authorized publishing the approved application and deploying it to the existing Forge server skola. Intended domain: aptauja.mikausis.lv. Temporary working domain: aptauja.kingdom.lv. Mikausis DNS access is unavailable.

## Scope

- Publish the reviewed application changes currently in this checkout to the existing public repository.
- Create a dedicated isolated Forge site and site user on server 1273956, using the server's supported PHP runtime and the repository lock file.
- Store the production environment, encryption key, SQLite database and sessions outside release replacement paths. Never copy the local database, credentials or encryption key.
- Configure both requested hostnames, with aptauja.kingdom.lv as the working application URL until Mikausis DNS is available. Create only the requested kingdom.lv DNS record, then install and verify HTTPS.
- Use the existing database seeder for a new draft survey only. Do not publish the seeded survey or transfer local survey responses without user direction.
- Preserve all other skola sites, shared services and local services.

## Files and resources

Application release includes the intentional tracked and new application, view and test changes shown by git status. No composer or dependency updates are planned. Deployment configuration is generated under artifacts/release-skola-20261030-* and installed only for the new site. Server-only environment and credentials are private and excluded from Git.

Potential application paths, only if deployment verification proves a change necessary: bootstrap/app.php, config/app.php, config/session.php, docs/forge.md. Re-read before any edit and keep changes within the deployment requirement.

## Gates

1. Inspect server, DNS and repository. Run the full PHP suite with forced SQLite :memory: and the full Node suite.
2. Review the concrete release and deployment scripts once through the required Codex delegation. Resolve material findings before production activation.
3. Commit and push only reviewed public source. Configure the new site, persistent state, repository and deployment script.
4. Verify deployment commit, HTTPS, public and admin pages, assets, secure cookies, sensitive path denial, database integrity and that existing sites remain intact.

## Recovery

Record site/domain IDs and the deployed commit. Keep the first installation unadvertised until checks pass. On failed activation, leave the new site in maintenance mode and preserve its database and encryption key. Never reset or remove existing server data. Keep later database backups consistent through SQLite's backup API.
