# 实际检查和测试结果

检查日期：2026-10-08。所有运行均针对工作区的隔离环境，未访问或修改生产论坛。后端使用官方 `flarum/testing 2.0.0-rc.8` 和真实 HTTP 请求管线；浏览器测试使用实际安装的论坛，直接检查投稿返回的 JSON。

## 环境

| 项目 | 实际版本 / 配置 |
| --- | --- |
| 操作系统 | Windows，PowerShell |
| PHP | 8.4.26 |
| Flarum core | 2.0.0-rc.8，官方 commit `f49072ab828fa19e92460b9ffd707d41ca9aaa04` |
| Diff | huseyinfiliz/flarum-diff 2.0.0，官方发行 commit `d73d0ac7b0d217b09769270891421fb7cdca8543` |
| 本次可选组合 | fof/seo 4.0.0-beta.9；fof/polls 3.0.0-rc.4；fof/split 2.0.0-beta.4；huseyinfiliz/leaderboard 2.1.0，均实际 Composer 安装 |
| PHP 测试 | PHPUnit 12.5.38，process isolation |
| 数据库 | 独立 SQLite；MariaDB 11.4.5 / InnoDB |
| 前端工具 | Node 24.18.0，npm 11.16.0，TypeScript 5.9，Webpack 5.111.1，官方 flarum-webpack-config 3.0.4 |
| 浏览器 | Playwright 1.63.0，Microsoft Edge，headless，1280 × 900 |
| 测试站 | `.workbench/site`，仅监听 127.0.0.1，数据库 `anonymous_extension_test_site` |

## 结果

| 检查 | 结果 |
| --- | --- |
| Composer 依赖安装 | 扩展开发目录及独立宿主论坛均实际安装成功；宿主识别 `adminlby/flarum-anonymous:dev-main` |
| 生产 copy repository 更新复现 | 独立 Git-free 源码、symlink=false 实际安装成功；源文件修改不自动刷新副本，reinstall 后相同。模拟 metadata 变化后定向 dry-run / update 只有本包 1 update，0 installs / removals；随后 reinstall 成功，其他已装包版本不变 |
| `composer validate --strict` | 通过，退出 0 |
| PHP syntax check | **75 个 PHP 文件**全部通过，包含 extend.php、全部后端、迁移、测试和工具 |
| `npm run typecheck` | 通过 |
| `npm test` | **16 / 16** 身份交互单元用例通过 |
| `npm run build` | forum / admin 生产构建成功，已交付 JS 与 source maps |
| PHPUnit / SQLite | **131 tests，2252 assertions，0 failures，0 errors，0 skipped**，退出 0 |
| PHPUnit / MariaDB | **131 tests，2241 assertions，0 failures，0 errors，0 skipped**，退出 0 |
| 真实浏览器回归 | **26 / 26** 通过；0 skipped、0 flaky、0 pageerror；含双向转换、Polls 投票列表与三语转换 / 编辑 UI |
| 三语 locale | English / zh-Hans / zh-Hant 的完整 key 对齐、非空和静态 UI key 覆盖检查通过 |
| migration | 本次无新增；沿用四个已存在迁移。SQLite 与 MariaDB 重复 up 检查通过；真实测试站重复 migrate 输出 Nothing to migrate |
| 生命周期与 down/up | 真实 CLI disable / enable / migrate:reset / enable / migrate 通过；回滚只移除扩展元数据，所有 core 列、帖子内容、用户 ID 及原生 Diff rows 保留；测试站匿名和编辑快照已恢复 |
| 回滚隐私保护 | 未启用新版 Anonymous 后端的独立 guard 测试通过，包含 Diff、SEO、Polls、Leaderboard；精确 .old 目录、原文件备份、兼容 bundle 保留与重复执行通过 |
| Flarum cache clear | 在真实宿主反复执行成功，最后执行 `php flarum cache:clear` 成功 |
| 生产更新与回滚命令 | 3 个 shell 命令块通过 Bash 语法检查；没有执行生产命令 |
| 生产压缩包 | 82 个运行 / 构建 / 文档文件解压后与当前源码逐文件相同；根目录直接包含 composer.json、extend.php，无外层目录及依赖 / 测试数据库 |
| core / vendor 对比 | 与另一个独立 Composer 安装的 core + 7 个官方包 + Diff + 四个可选包逐文件内容对比，**2593 个文件相同，0 差异**；没有手工修改 vendor |

PHPUnit 的 SQLite / MariaDB assertion 数量不同来自官方 testing 对两种数据库查询执行的额外检查，功能用例数相同。包含二十个不同作者的列表测试保留查询数量检测，用于确认扩展没有增加每个用户一条查询的加载模式。

有 **3 条上游 PHP 8.4 deprecation**：`vendor/middlewares/utils/src/Factory.php::createUploadedFile()` 的 `$size`、`$filename`、`$mediaType` 隐式 nullable 参数。没有测试失败或本扩展的 deprecation，未为消除提示修改 vendor。Webpack 成功构建时另有官方配置产生的 `DEP_WEBPACK_MAIN_TEMPLATE_REQUIRE` 提示。

## 覆盖的关键功能和泄露检查

后端检查包含：匿名主题与首帖、普通回复、Markdown / PostMention 引用、其他注册 Post 类型、明确实名自定义投稿、独立开关与权限、布尔输入校验、编辑不可切换身份、删除首帖、删除用户、末帖匿名与末帖引用丢失、所有作者关系与 IP、稀疏字段和嵌套 include、仅有 ID 的已删除模型、正负 author filter、用户页列表、计数和排序、参与人数 oracle、SSR / preload、通知真实数据库记录、旧通知行、旧队列蓝图邮件实际渲染、已删除源帖的延迟邮件抑制及通知清理、Audit 追溯与有限权限隔离、已取消类型注册的匿名帖审计保护、主题改名事件、未知通知抑制，以及没有 Mentions / Subscriptions / Audit 等可选扩展时的正常创建和 reveal。

新增后端测试覆盖匿名作者锁定、旧 revision 0、匿名编辑实名帖、编辑身份与旧记录不可变、关闭开关、无 Diff、Diff mainPostOnly、直接及嵌套历史权限、sparse includes、删除账号后追溯、管理员显式 reveal/Audit、原作者 rollback、中途 Diff 元数据缺失时的匿名 reservation、二十条历史批量元数据加载、追加迁移，以及旧后端回滚 guard。

本次新增检查：获授权的首帖 / 回复双向转换、普通用户及仅编辑 / reveal 权限拒绝、普通 PATCH 不能转换、管理员常规读取仍匿名、原作者全部自身历史与 revision 0、其他匿名编辑者保留、真实 user_id 保留、站内通知遗留 sender / isAnonymous 的双向状态、结构化原文引用及 Diff 压缩归档、转匿名后全文 / author 搜索与 SSR/preload、同作者改名事件、相同状态幂等且不重复 Audit、无投稿 / 通知事件、晚期异常与损坏归档全部回滚；并在四个可选扩展同时启用时重复转换回归。邮件排除于本次转换范围。

浏览器 26 项实际执行（既有 21 项加本次 5 项）：

1. 点击 Start a Discussion 后先出现身份弹窗，编辑器尚未创建；实际匿名提交、匿名头像和 own hint 正确。
2. 普通 Reply 在编辑器打开前选择身份，实际 POST 返回匿名资源。
3. 普通匿名回复关闭时，帖子回复按钮仍按独立引用开关允许匿名引用提交。
4. 选中文字 Quote 按钮先选择身份，再插入本地化匿名引用。
5. 默认实名，切换匿名，实际创建 API 状态正确。
6. 默认匿名，切换实名，实际创建 API 作者 relationship 正确。
7. Admin 默认匿名显示；显式 reveal 后弹窗显示真实作者，post.user() 及正常帖子显示保持匿名。
8. Guest SSR / preload / 深层 include / 作者 profile filter 无真实作者信息。
9. Admin 展示全部类型开关、身份模式和 reveal 权限。
10. 取消身份弹窗，编辑器保持关闭，无未处理 promise 异常。
11. 选择 Show identity，实际创建保持实名。
12. 编辑匿名内容无需再选身份；own hint 关闭时隐藏提示，编辑响应仍匿名且不含作者 / 编辑者关系。
13. 已登录 Admin 的 reveal 请求缺少 CSRF token 时被核心拒绝，不返回作者身份。
14. 自动改名事件使用匿名头像 / 图标与名称，核心事件子类也正确显示“匿名用户”。
15. 匿名原帖作者编辑身份锁定，刷新后 Diff 列表及弹窗显示匿名图标 / 名称，自己的编辑提示正确。
16. 实名帖子可由匿名编辑者编辑；原帖仍实名，编辑 API 不返回真实 editedUser，历史显示匿名。
17. 其他编辑者可选择实名编辑匿名帖；原作者及其旧历史仍匿名。
18. 管理员显式查看匿名编辑者后，普通 Diff actor store 仍为空。
19. 编辑者 reveal 缺少 CSRF 被拒绝；后台编辑记录身份设置及 Diff 依赖说明正常。
20. 简中匿名名称、编辑弹窗 / 按钮、Diff 历史和 Admin 设置实际渲染正确。
21. 繁中相同路径实际渲染正确。
22. 管理员将首帖实名→匿名→实名，刷新后的 Post/Discussion API 关系跟随转换。
23. 无转换权限者没有菜单且 API 返回 403；登录 Admin 缺少 CSRF 的转换请求被核心拒绝。
24. Polls 作者自己的投票显示匿名图标、无用户链接；转回实名可见，随后再匿名并刷新。
25. 简中转换菜单、确认弹窗和双向操作使用实际 locale key。
26. 繁中同样验证。

中文浏览器用例在独立测试站选择对应 locale 后通过核心 cache API 重建相应语言资产；核心其他界面由已安装 English 包回退，本扩展使用实际中文 catalogue。讨论提交实际选择 Tags 的 General 标签，没有为简化测试关闭 Tags。未将生产语言包其他界面算入本扩展验收。

本轮开始前隔离论坛已有一条最初开发时 Less 变量错误日志（11:40 UTC）；本轮测试、构建、迁移和页面请求没有新增 ERROR。该旧错误的样式代码早已改为 rc.8 CSS custom properties。

本次 Composer 实际新增四个精确可选开发包，扩展开发目录和独立论坛均安装成功；既有版本没有升级或移除。未把依赖 advisory 扫描算作本次匿名安全验收；结论来自实际 JSON API、SSR、浏览器、数据库检查和源码审查。

实际身份泄露审查结论及范围见 [THREAT-REVIEW.md](THREAT-REVIEW.md)。

## 复现

开发目录安装依赖后，SQLite 默认检查：

```sh
composer test:setup
composer test
composer lint
composer validate --strict
cd js
npm ci
npm run typecheck
npm test
npm run build
```

MariaDB 检查使用独立数据库；先手动创建数据库和测试账号，再将连接信息提供给官方 setup。以下 PowerShell 使用自行配置的测试凭据：

```powershell
$env:FLARUM_TEST_TMP_DIR = Join-Path (Get-Location) '.workbench/testing-mysql'
$env:DB_DRIVER = 'mariadb'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_DATABASE = 'anonymous_extension_test'
$env:DB_USERNAME = 'flarum_test'
$env:DB_PASSWORD = '<测试数据库密码>'
composer test:setup
composer test
```

`test:setup` 会删除目标数据库中的全部表；脚本拒绝非 SQLite 且不以 `anonymous_extension_test` 开头的数据库。每套测试使用不同的 `FLARUM_TEST_TMP_DIR`，不要并行运行共用一个测试目录 / 数据库的用例。

真实浏览器和生命周期检查需要一个已安装并启用本扩展、English、Mentions、Subscriptions、Audit 的独立 rc.8 测试站。`tests/e2e/seed.php` 会重建专用测试 fixture 并生成短期 token，只在本地测试站目录写入；文件不纳入 Git。

```powershell
$env:ANONYMOUS_E2E_SITE = 'D:/path/to/isolated-test-site'
$env:ANONYMOUS_E2E_URL = 'http://127.0.0.1:8088'
# 测试站应预先通过本地 PHP / Web 服务器运行，数据库也已启动。
php tests/e2e/seed.php
$env:PLAYWRIGHT_CHANNEL = 'msedge'
Push-Location js
npm.cmd run test:e2e
Pop-Location
php tools/lifecycle-check.php
```

若使用 Playwright Chromium，先在 js 目录运行 `npx playwright install chromium` 并省略 `PLAYWRIGHT_CHANNEL`。测试 Admin fixture 的默认账号密码需与独立站点安装时一致，见 seed / e2e 测试文件；不要向生产站导入这些测试用户。

本工作区保留运行证据于忽略目录：`.workbench/phpunit-sqlite.xml`、`.workbench/phpunit-mariadb.xml`、`.workbench/playwright-results.json`、`.workbench/php-lint.log`。这些文件、便携 PHP / MariaDB、测试站、测试数据库、node_modules 和 vendor 均未作为扩展源码交付文件。
