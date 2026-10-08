# Anonymous Posting development and production updates

These instructions record the user's production workflow. This extension is already installed and enabled. Treat future work as updates, not first installation.

- Source: `D:\Desktop\Projects\flarum-anonymous`.
- Package `adminlby/flarum-anonymous`, extension ID `adminlby-anonymous`, exact Flarum `2.0.0-rc.8`, PHP `8.4`.
- Host forum `/opt/yeedang-bbs/flarum`, Docker PHP container `yeedang-bbs-php`, container forum `/var/www/flarum`.
- Host extension source `/opt/yeedang-bbs/flarum/extensions/flarum-anonymous`; container source `/var/www/flarum/extensions/flarum-anonymous`.
- Composer path repository is already configured with `symlink=false`. Always refresh the installed copy with Composer after replacing the source.
- Do not rerun `configure-repository.php` unless that path configuration changes. Do not repeat `extension:enable` for this deployed extension.
- Build forum/admin locally and ship `js/dist`. Production has no npm. `flarum-realtime` is disabled.
- Never edit Flarum core or vendor. Composer may install/refresh declared packages normally.
- Original anonymous post authors remain anonymous in ordinary edits, regardless of the editor-identity setting. A separate, authorized moderation action may convert existing posts between anonymous and named, synchronizing related author identity. Other editors' independently anonymous history remains private. Ordinary edit requests cannot change post anonymity; real user IDs are never replaced.
- The user does not use email notifications and excludes email from the moderation conversion requirement. Conversion must not send notifications or emails. Continue protecting on-site notifications and stored mentions/history.

Before delivery: lint PHP; validate Composer; run applicable tests; build and check dist; check safe/repeatable migrations; verify English/zh-Hans/zh-Hant; test JSON API anonymity including search, profiles, notifications, mentions, SSR and optional Diff. Record limits honestly; do not claim untested third-party integrations are compatible.

Package in PowerShell with root files directly inside the archive:

```powershell
tar.exe -czf "$env:TEMP\flarum-anonymous.tar.gz" `
  -C "D:\Desktop\Projects\flarum-anonymous" `
  composer.json extend.php migrations src js/dist less locale views tools README.md LICENSE docs
```

Upload to `/tmp/flarum-anonymous.tar.gz`. Never infer SSH credentials or reuse an unrelated project's release skill. Provide upload commands with placeholders when host/user/port are unavailable.

Before replacing production source, copy it into timestamped `/opt/yeedang-bbs/backups/flarum-anonymous-*`. Extract into a temporary directory, validate `composer.json` and `extend.php`, and retain the previous source as `flarum-anonymous.old`. See `docs/PRODUCTION-UPDATE.md` for exact update and rollback commands.

For code/build/locale-only changes with unchanged dependencies, use `COMPOSER_ALLOW_SUPERUSER=1 composer reinstall adminlby/flarum-anonymous`. Analyze dependency/constraint/metadata changes before using a targeted `composer update adminlby/flarum-anonymous`. Do not default to `--ignore-platform-reqs`, `-W`/`--with-all-dependencies`, or removing other dependencies. Run `php flarum migrate`, `php flarum cache:clear`, verify `php flarum info`, and inspect the newest Flarum log. Preserve quick rollback and explicitly explain database/identity compatibility limits.

Every completed change must deliver: (1) summary and breaking changes; (2) changed file list; (3) migration status; (4) composer.json changes; (5) update versus reinstall; (6) local test results; (7) frontend build results; (8) anonymity review; (9) PowerShell archive command; (10) upload command; (11) directly executable production update commands; (12) rollback commands. Do not claim deployment/production acceptance was performed without actually doing it.

Production acceptance covers home/Admin, anonymous/named discussions and replies, quotes, refresh persistence, ordinary users and privileged reveal, JSON API user/userId relationships, user profile and author search, notification/mentions, all three locales, and absence of new log errors.
