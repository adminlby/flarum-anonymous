# Anonymous Posting for Flarum 2.0.0-rc.8

适用于 **Flarum 2.0.0-rc.8 / PHP 8.4** 的完整匿名投稿扩展。匿名状态由服务器保存和检查，数据库中的真实 `user_id` 用于原有权限、编辑、限流及管理追溯。没有假用户，也不修改 core 或 vendor。

扩展包名：`adminlby/flarum-anonymous`；扩展 ID：`adminlby-anonymous`。已包含编译后的 `js/dist`，服务器无需 Node.js。生产站已部署；后续修改采用 [生产更新与回滚流程](docs/PRODUCTION-UPDATE.md)。此工作区的本次修改尚未上传生产服务器。

## 功能和默认值

| 设置 | 默认值 | 行为 |
| --- | --- | --- |
| 启用匿名投稿 | 开启 | 关闭后停止新的匿名投稿，已有匿名内容仍受保护 |
| 允许匿名主题 | 开启 | 独立控制新主题及其首帖 |
| 允许匿名普通回复 | 开启 | 独立控制普通回复 |
| 允许匿名引用回复 | 开启 | 独立控制引用、帖子 mention 回复、Markdown / BBCode 引用 |
| 允许其他帖子类型的匿名投稿 | 开启 | 控制通过核心 `Post\Event\Saving` 流程创建的其他用户投稿类型 |
| 发布身份交互模式 | 每次询问 | 另有默认实名、默认匿名；编辑器内可以切换本次投稿身份 |
| 作者自己的匿名提示 | 开启 | 显示本地化的“这是你发布的匿名内容” |
| 允许选择编辑记录的身份 | 关闭 | 需要 Diff 2.0.0；仅决定本次新增编辑记录的匿名 / 实名，匿名原帖作者始终保持匿名 |

每次询问模式会在核心 Discussion / Reply Composer **load/show 之前**要求选择“匿名发布 / 显示身份”。普通回复、帖子回复按钮和选中文本引用使用相同的身份选择弹窗。取消选择不会打开编辑器。默认身份模式直接打开编辑器并提供身份切换按钮。

引用类型由服务器根据原始输入和核心格式化内容识别，包括 `>`、`[quote]`、帖子 mention、`QUOTE` / `POSTMENTION`。删除引用标记后，普通文字按普通回复处理。其他扩展的自定义投稿应沿用核心 `Model.save()`、`Post` 类型注册和 `Post\Event\Saving`，不能绕过它们直接写数据库。

普通投稿和编辑不能改变既有帖子的发布身份。另有独立管理权限允许对已有帖子执行匿名 / 实名转换；真实 `user_id` 始终保留。主题改名等核心事件继承匿名主题发起人的匿名状态，避免事件帖子显示真实身份。

### 管理员转换已有帖子的身份

后台授权 `anonymous.changeIdentity` 后，帖子管理菜单提供“转为匿名 / 公开作者身份”，确认后由服务器事务处理。此权限与 reveal、普通编辑和匿名投稿权限独立；默认仅 Administrator，其他组需手动授权。匿名投稿总开关关闭也不阻止获授权的历史内容管理。

- 转换首帖时同时更新主题发起人身份及同一作者的核心事件帖子；转换普通回复不改其他回复或主题发起人。
- 隐藏该作者的相关 Diff 历史和原始 revision 0，保留真实 ID；转回实名时恢复该作者的记录，其他编辑者独立选择匿名的记录继续保密。
- 同步存储的 POSTMENTION 名称、编辑原文 token、Diff 历史中的引用及压缩归档；源帖子、历史、引用任何一步失败都会回滚整笔操作。
- 站内通知按源帖当前状态输出作者，过滤遗留匿名 / 实名 payload；不发送新的通知或邮件。按本项目要求，邮件不属于本次转换验收范围。
- 继续保护 Post/Discussion 深层 API、搜索、用户主页、SSR/preload、Audit。确认后重新加载当前页面，清除操作者浏览器中旧的作者关系。
- 可选适配实际验证了 `fof/seo 4.0.0-beta.9` JSON-LD、`fof/polls 3.0.0-rc.4` 创建者及作者自己的投票、`fof/split 2.0.0-beta.4` 首帖身份继承和 `huseyinfiliz/leaderboard 2.1.0` 公开积分 / 计数。

转回实名会公开原作者账号，确认弹窗明确说明。新设置和管理操作均支持三语。此更新没有新增 migration，复用现有匿名字段与编辑身份元数据；代码回滚保留已执行的身份决策，不能撤销数据库里的转换。

启用 `huseyinfiliz/flarum-diff 2.0.0` 后，编辑历史默认由 API 隐藏匿名原帖作者，包括旧记录、原始 revision 0 和正常管理员读取。后台可允许其他编辑者在编辑器打开前选择本次记录的匿名 / 实名；默认身份模式仍提供编辑器内切换。**匿名原帖作者始终被锁定为匿名**，服务器拒绝其实名编辑请求。此选择不会改变原帖或旧 revision 的匿名状态。历史按钮与弹窗使用匿名图标和三语名称；有 reveal 和历史查看权限的用户可以显式查看真实编辑者，并由 Audit 记录。

后台关闭身份选择、关闭匿名投稿总开关或关闭 Diff，都不会让已存的匿名编辑关系恢复实名；彻底禁用本扩展则会移除它的保护。Diff 自身设置只记录首帖时，不向未记录历史的普通回复提供身份选择。已匿名的原帖作者仍保持匿名。

前台、Admin、弹窗、权限、邮件、SSR 和审计说明均提供 English、简体中文、繁體中文 locale key。论坛其他界面的中文翻译由相应语言包提供。

## 已部署站点更新

此项目的生产论坛使用 `yeedang-bbs-php`，宿主源码位于 `/opt/yeedang-bbs/flarum/extensions/flarum-anonymous`，容器内为 `/var/www/flarum/extensions/flarum-anonymous`。path repository 已配置且 `symlink=false`，因此替换源码后还要刷新 vendor 副本。无需重复配置 repository 或启用扩展。本次迁移及 Composer 决定、PowerShell 打包、上传、备份、更新、验收和回滚的可复制命令均见 [PRODUCTION-UPDATE.md](docs/PRODUCTION-UPDATE.md)。

当前已运行验证核心保护、Diff，以及 SEO / Polls / Split / Leaderboard 的组合适配。其余插件仍需独立验证，不能宣称用户整份插件清单已全部兼容，详见 [兼容性报告](docs/COMPATIBILITY.md)。

## 新测试论坛首次安装参考

先备份论坛数据库和 Composer 文件，确认宿主运行 PHP 8.4，`flarum/core` 已锁定为 `2.0.0-rc.8`。下面命令在**宿主 Flarum 根目录**运行，扩展源码放在该目录之外。

本地路径安装，Linux 示例：

```sh
composer config repositories.anonymous '{"type":"path","url":"/srv/extensions/flarum-anonymous","options":{"symlink":false,"versions":{"adminlby/flarum-anonymous":"dev-main"}}}'
composer require adminlby/flarum-anonymous:dev-main
php flarum extension:enable adminlby-anonymous
php flarum migrate
php flarum cache:clear
```

本工作区的 PowerShell 路径安装示例：

```powershell
php D:/Desktop/Projects/flarum-anonymous/tools/configure-repository.php .
composer require adminlby/flarum-anonymous:dev-main
php flarum extension:enable adminlby-anonymous
php flarum migrate
php flarum cache:clear
```

PowerShell 示例通过脚本写入同样的 path repository，避免 Windows PowerShell 5.1 的原生参数 JSON 引号丢失。脚本保留宿主其他 repository，并备份原 `composer.json` 到 `composer.json.anonymous-backup`。

也可在 Admin 扩展列表启用。核心 enable 操作自动运行本扩展的迁移。论坛升级到其他 RC / 正式版前，需要重新验证 API 兼容性；本包的 Composer 约束刻意只允许 rc.8。

其他 path repository 测试论坛在依赖 / 元数据变化时更新：

```sh
composer update adminlby/flarum-anonymous
composer reinstall adminlby/flarum-anonymous
php flarum migrate
php flarum cache:clear
```

依赖与元数据没有变化时优先只运行 `composer reinstall`。实际生产使用上方专用更新流程。队列 worker 应按宿主部署流程重新加载，使新容器绑定和邮件保护生效。

## 权限和管理追溯

| 权限 | 用途 | 默认授权 |
| --- | --- | --- |
| `anonymous.startDiscussion` | 匿名发布主题，同时需要核心主题发布权限 | Members |
| `discussion.anonymousReply` | 匿名回复 / 引用 / 其他投稿，同时需要核心回复权限 | Members |
| `anonymous.reveal` | 显式查看真实作者 | 仅核心 Administrator 的默认全部权限；其他组需手动授权 |
| `anonymous.changeIdentity` | 将已有帖子转为匿名 / 实名 | 仅 Administrator；与 reveal、普通编辑权限独立 |

具有 reveal 权限的用户默认仍看到匿名头像和“匿名用户”。帖子操作菜单提供“查看真实作者”，打开弹窗后再次主动点击才发起 POST 请求。返回结果仅保存在弹窗内，不写入 Flarum User store，不改变帖子旁的身份显示。

rc.8 的 `flarum/audit` 提供公共 `Flarum\Audit\Extend\Audit::listen()`，因此启用 Audit 时记录 `anonymous.author_revealed`，包含操作人、原始 user ID、post ID、discussion ID。没有 reveal 权限的审计查看者看不到能关联匿名作者的日志。未启用 Audit 时，仍发出 `AdminLby\Anonymous\Event\AuthorRevealed` 作为兼容扩展点；不写入核心内部 API。

核心删除用户时会将外键 `posts.user_id` 置空。本扩展额外保存不可通过编辑修改的私有 `anonymous_author_id` 数字快照，使删除账号后仍可通过 reveal 追溯原始 ID；名称显示“用户已删除”。该列没有 User relationship，也不出现在普通 API。

## API

创建主题 / 回复时，在核心 JSON:API attributes 中使用布尔值 `isAnonymous`：

```json
{
  "data": {
    "type": "posts",
    "attributes": {"content": "This reply is anonymous.", "isAnonymous": true},
    "relationships": {"discussion": {"data": {"type": "discussions", "id": "42"}}}
  }
}
```

创建主题使用 `POST /api/discussions`，attributes 为 `title`、`content`、`isAnonymous`。客户端交互默认值不会隐式改变第三方 API 请求：省略 `isAnonymous` 的 API 投稿默认为实名。

匿名 Post 返回 `isAnonymous`、`isMine`、`canRevealAnonymousAuthor`；`user`、`editedUser`、`hiddenUser` relationship 为 null，`ipAddress` 为 null / 不可见。作者本人也不获得真实作者 relationship。匿名 Discussion 的 `user` 为 null；最近一帖匿名时 `lastPostedUser` 为 null。`firstPost`、`lastPost`、`mostRelevantPost` 及所有嵌套 Post 仍使用同一保护。

管理转换：`POST /api/anonymous/posts/{id}/identity`，JSON body 为 `{"data":{"attributes":{"isAnonymous":true}}}`，false 表示实名。要求登录、帖子可见性、`anonymous.changeIdentity` 与核心 CSRF。Post 的 `canChangeAnonymousIdentity` 是只读能力字段。返回独立 `anonymous-identity-changes` 资源，只含状态、discussion ID 及 changed，不含作者；响应 private/no-store。重复相同状态幂等，不重复审计。普通 `PATCH /api/posts/{id}` 仍不能转换身份。Audit 启用时记录 `anonymous.identity_changed`，未启用时仍发送 `AdminLby\Anonymous\Event\IdentityChanged` 扩展事件。

显式追溯：`POST /api/anonymous/posts/{id}/reveal`。需要登录、reveal 权限及帖子可见性，并经过核心 CSRF 中间件。结果为独立的 `anonymous-author-reveals` 资源，包含 `userId`、`displayName`、`username`、`deleted`。响应具有 `Cache-Control: private, no-store`；无权限时不会返回身份数据。

编辑记录身份：Diff 已启用且后台允许时，`PATCH /api/posts/{id}` 的 attributes 可传 JSON 布尔值 `editHistoryAnonymous`。匿名原帖作者只允许 true 或省略；false 返回 422。省略时继承原帖身份，客户端默认模式只控制交互。无内容变化不会改写既有记录。Post 的 `canChooseEditHistoryIdentity` 说明当前帖子是否允许选择；`lastEditIsAnonymous` 只读，实名帖的匿名编辑同样屏蔽 `editedUser`。

历史使用 Diff 原生 `/api/diff?filter[post_id]={id}`。匿名 revision 返回 `isAnonymous`、`isMine`、`canRevealAnonymousEditor`，其 actor/deletedUser/rollbackedUser 关系为空。记录身份没有 PATCH 更新端点。显式追溯 `POST /api/anonymous/diff/{id}/reveal` 还要求 `viewEditHistory` 权限，返回独立 `anonymous-edit-author-reveals` 资源并触发 `EditorRevealed` 兼容事件；Audit 已启用时记录 `anonymous.edit_author_revealed`。

作者过滤正查询只返回实名内容；负查询中的匿名结果与查询的作者无关，避免通过逐个排除反查身份。用户个人页公开计数、用户列表的计数排序均只统计实名活动。API 隐藏真实 `participantCount`，避免通过去重人数变化关联匿名回复的作者；核心数据库计数仍用于原有权限。

## 通知、邮件和兼容范围

本次管理转换只验收站内通知，邮件按用户要求排除；转换动作本身不发送任何通知或邮件。原有投稿邮件保护仍保留。已经送达或被第三方缓存的数据不能通过一次身份转换召回。

已验证 rc.8 核心与同版本 English、Mentions、Subscriptions、Likes、BBCode、Audit 的安装及运行。Mentions / Subscriptions 的 `newPost`、`postMentioned`、`userMentioned`、`groupMentioned` 和核心 `discussionRenamed` 使用匿名通知数据及本地化通用邮件模板。发送者 relationship 不含真实作者，旧队列 blueprint 在发送阶段再次检查数据库状态。引用匿名帖子的存储 XML、编辑原文、渲染结果、搜索索引内容都去除自动产生的真实作者名称。

未适配的匿名 Post 通知类型会停止投递，避免未知 payload / 邮件模板泄露身份；这也包括在匿名帖子上产生的未适配通知类型。其他扩展的独立 API、搜索驱动、导出、Webhook、推送及邮件发送器需要分别集成和检查。本扩展没有宣称任意第三方插件都已验证。

通知或邮件任务引用的源帖子已经删除时，不会使用旧蓝图内的缓存作者继续发送邮件；旧通知 API 同样隐藏无法验证的缓存发送者。删除通知时仍能匹配匿名蓝图并清理旧记录。

匿名保护针对系统产生的作者关联信息。帖子作者自行写入的姓名、外链、签名、引用文字，以及活动时间和写作风格，仍可能表明身份。数据库、备份、服务器日志和有 reveal 权限的管理人员保留真实身份访问能力。

## 暂停、禁用和回滚

只想停止新的匿名投稿时，在 Admin 关闭“启用匿名投稿”总开关，**保留扩展启用**，然后清缓存。已有匿名内容继续受保护。

完全禁用扩展时，帖子内容、真实作者 ID 和匿名标志都保留，可重新启用恢复匿名显示：

```sh
php flarum extension:disable adminlby-anonymous
php flarum cache:clear
# 恢复
php flarum extension:enable adminlby-anonymous
php flarum migrate
php flarum cache:clear
```

**完全禁用后核心会按真实 `user_id` 显示已有作者。** 不修改 core 的扩展在禁用后无法继续过滤核心 API。需要保留匿名性的站点应使用总开关；完整卸载前应暂停公开访问并决定如何处理历史匿名内容。

以下是完整卸载参考，**不是生产更新或代码回滚流程**。先保存数据库备份。`migrate:reset` 会删除匿名标志、作者快照、编辑身份元数据和默认权限，不能在重装时自动恢复这些信息；核心帖子、Diff 原生记录与用户数据保留。生产回滚使用 [专用流程](docs/PRODUCTION-UPDATE.md)，保留匿名元数据。

```sh
php flarum extension:disable adminlby-anonymous
php flarum migrate:reset --extension=adminlby-anonymous
composer remove adminlby/flarum-anonymous
php flarum cache:clear
```

恢复 reset 前的匿名状态需要恢复备份中的匿名列和对应权限；只重新安装会把历史帖子按实名默认值处理。

## 开发和检查

在扩展目录运行：

```sh
composer install
composer validate --strict
composer lint
composer test:setup
composer test
cd js
npm ci
npm run typecheck
npm test
npm run build
```

默认 PHP 集成测试使用 `tests/tmp` 中的独立 SQLite。官方 Flarum testing setup 会清空目标数据库；测试脚本拒绝使用未命名为 `anonymous_extension_test*` 的非 SQLite 数据库。MariaDB 检查方法及真实浏览器配置见 [测试报告](docs/TEST-RESULTS.md)。

浏览器测试需要安装并启用扩展的**独立测试站**，测试 fixture 会清空该测试站的帖子 / 通知。配置 `ANONYMOUS_E2E_SITE` 后先运行 `php tests/e2e/seed.php`，再运行 `npm run test:e2e`。生命周期测试用 `php tools/lifecycle-check.php`，同样拒绝操作生产数据库名称。

更多交付细节：

- [rc.8 API 研究和实施对应](docs/RC8-API-NOTES.md)
- [匿名身份泄露 threat review](docs/THREAT-REVIEW.md)
- [检查、测试结果和复现命令](docs/TEST-RESULTS.md)
- [完整修改文件清单](docs/CHANGED-FILES.md)
- [生产更新、上传与快速回滚](docs/PRODUCTION-UPDATE.md)
- [指定插件版本兼容性核查](docs/COMPATIBILITY.md)
