# 本次更新文件清单

本次新增获授权的匿名 / 实名转换、相关历史 / 引用 / 通知同步，以及四个可选扩展的匿名适配。相对本轮开始时保存的本机 SHA-256 基线，共 **49 个新增 / 修改路径**（新增 17，修改 32，删除 0）。未读取生产源码，因此这不是服务器 Git diff；当前 Git 基线只有 LICENSE，其余扩展源码尚未提交。

没有修改 Flarum core/vendor；Composer 正常安装依赖。没有新增 migration。composer.json 仅增加四个可选适配的开发测试依赖和可选依赖声明，运行时 PHP/core 约束不变。

## 本轮新增

```text
js/src/forum/components/ChangeIdentityModal.tsx
js/src/forum/installCompatibility.tsx
src/Api/ChangeIdentityController.php
src/Compatibility/Extenders.php
src/Compatibility/InheritSplitIdentity.php
src/Compatibility/LeaderboardServiceProvider.php
src/Compatibility/PollPrivacy.php
src/Compatibility/ProtectSeo.php
src/Compatibility/PublicLeaderboard.php
src/Compatibility/PublicLeaderboardController.php
src/Compatibility/PublicLeaderboardPoints.php
src/Event/IdentityChanged.php
src/Moderation/ChangeIdentity.php
src/Moderation/SynchronizeReferences.php
tests/integration/ConversionCompatibilityTest.php
tests/integration/IdentityConversionTest.php
tests/integration/RollbackCompatibilityTest.php
```

## 本轮修改

```text
AGENTS.md
README.md
composer.json
composer.lock
docs/CHANGED-FILES.md
docs/COMPATIBILITY.md
docs/PRODUCTION-UPDATE.md
docs/RC8-API-NOTES.md
docs/TEST-RESULTS.md
docs/THREAT-REVIEW.md
extend.php
js/dist/admin.js
js/dist/admin.js.map
js/dist/forum.js
js/dist/forum.js.map
js/src/admin/index.tsx
js/src/forum/index.tsx
locale/en.yml
locale/zh-Hans.yml
locale/zh-Hant.yml
src/Access/AnonymousAuditScope.php
src/Access/AnonymousPolicy.php
src/Api/PostFields.php
src/Privacy.php
tests/e2e/anonymous.spec.cjs
tests/e2e/seed.php
tests/integration/AnonymousApiTest.php
tests/integration/RollbackPreparationTest.php
tests/integration/RollbackPrivacyTest.php
tools/lifecycle-check.php
tools/prepare-rollback.php
tools/rollback-privacy.php
```

生产包按用户指定清单包含运行源码、已编译前端、语言、视图、工具和文档。AGENTS.md、composer.lock、tests、vendor、node_modules 及隔离环境不进入生产压缩包。完整项目文件数为 119；便携运行时、数据库、Composer 缓存和打包临时目录均为忽略内容。

实际测试见 [TEST-RESULTS.md](TEST-RESULTS.md)，部署与回滚见 [PRODUCTION-UPDATE.md](PRODUCTION-UPDATE.md)，匿名审查见 [THREAT-REVIEW.md](THREAT-REVIEW.md)，适配版本与边界见 [COMPATIBILITY.md](COMPATIBILITY.md)。
