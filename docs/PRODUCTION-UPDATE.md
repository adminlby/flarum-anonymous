# 已部署论坛的更新与回滚

适用于用户现有 Docker 生产论坛。包 `adminlby/flarum-anonymous` / ID `adminlby-anonymous` 已安装、已启用；Flarum 为 `2.0.0-rc.8`，PHP 为 `8.4`。本机完成构建；服务器不运行 npm。`flarum-realtime` 已禁用。

| 路径 / 服务 | 值 |
| --- | --- |
| PHP 容器 | `yeedang-bbs-php` |
| 宿主论坛 | `/opt/yeedang-bbs/flarum` |
| 容器论坛 | `/var/www/flarum` |
| 宿主扩展源码 | `/opt/yeedang-bbs/flarum/extensions/flarum-anonymous` |
| 容器扩展源码 | `/var/www/flarum/extensions/flarum-anonymous` |
| 已配置 path repository | `/var/www/flarum/extensions/flarum-anonymous`，`symlink=false` |
| 上传包 | `/tmp/flarum-anonymous.tar.gz` |

无需重复运行 `configure-repository.php` 或 `extension:enable`。更新 source 后必须由 Composer 刷新 vendor 中的已安装副本。

## 本次版本的部署决定

新增独立 `anonymous.changeIdentity` 权限、帖子管理菜单及确认弹窗，可将已有帖子在匿名 / 实名间转换。同步作者自身的 Diff 记录、存储的帖子引用（含历史压缩归档）、首帖所属主题及同作者事件、站内通知；保留真实 `user_id`，不发新通知或邮件。邮件按用户要求排除。本次没有新增 migration，复用既有四个迁移中的匿名字段与私有编辑身份表。未部署上一版的站点仍通过正常 `php flarum migrate` 补齐第 4 个迁移。

`composer.json` 增加 SEO / Polls / Split / Leaderboard 的精确开发测试依赖及官方 optional-dependencies 声明；本次已实现并组合测试这四个适配。运行时 require 的 PHP/core 约束不变；生产清单已安装对应版本，开发依赖不会自动装入生产。本次使用**定向 composer update，再 reinstall 确保源码副本刷新**。不会主动更新其他扩展，也不使用 `-W` 或平台忽略参数。

行为变化：获授权的管理转换是普通编辑不可变规则的显式例外。转回实名公开原作者及该作者自己的历史，其他编辑者独立匿名的记录保密。Leaderboard 的公开积分排除匿名 / 私密 / 隐藏来源，并按原生 PointService 当前权重计算所有时间范围，可能改变公开排名；原生私有积分账本与总分不被删除或改写。Polls 隐藏关联匿名帖创建者及作者自身的投票关系，其他投票者不受影响。所有用户（含普通读取的管理员）默认看匿名身份，reveal 仍是独立操作。

没有新增 DDL，但管理操作会改写匿名状态和自动引用的作者标记。**代码降级仍需要下方回滚隐私保护步骤**，不能撤销已执行的数据库转换，也不能把源码回滚当作数据库回滚。`prepare-rollback.php` 为 `.old` 注册保护钩子，并保留本次四个可选适配及通知状态保护；原文件备份到旧目录的 tools/rollback-backups。整个流程不修改 core/vendor。若需要撤销数据库变更，应使用部署前数据库备份并评估期间新投稿。

本次只运行了本机隔离论坛验收，未连接生产服务器。其他插件的已知限制见 [COMPATIBILITY.md](COMPATIBILITY.md)。

## 本地打包及上传

在 Windows PowerShell 执行。压缩包根目录直接包含 `composer.json`、`extend.php` 等；不包含 vendor、node_modules、测试数据库或额外外层目录。

```powershell
tar.exe -czf "$env:TEMP\flarum-anonymous.tar.gz" `
  -C "D:\Desktop\Projects\flarum-anonymous" `
  composer.json extend.php migrations src js/dist less locale views tools README.md LICENSE docs
if ($LASTEXITCODE -ne 0) { throw "Packaging failed" }
tar.exe -tzf "$env:TEMP\flarum-anonymous.tar.gz" | Select-Object -First 15
Get-FileHash "$env:TEMP\flarum-anonymous.tar.gz" -Algorithm SHA256
```

SSH 地址、用户及端口未提供。替换下面两个占位值和端口后执行：

```powershell
scp.exe -P 22 "$env:TEMP\flarum-anonymous.tar.gz" "SSH_USER@SSH_HOST:/tmp/flarum-anonymous.tar.gz"
ssh.exe -p 22 SSH_USER@SSH_HOST
```

## 服务器更新命令：宿主机

使用能操作 `/opt/yeedang-bbs` 和 Docker 的 SSH 账号。先备份当前源码及宿主 Composer 文件，暂存解压、验证必需文件，然后替换。旧 `.old` / `.new` 目录若存在会移入此次备份；不删除原备份。

```sh
set -eu
FORUM=/opt/yeedang-bbs/flarum
EXT=/opt/yeedang-bbs/flarum/extensions/flarum-anonymous
BACKUP="/opt/yeedang-bbs/backups/flarum-anonymous-$(date +%Y%m%d-%H%M%S)"

test -d "$EXT"
test ! -L "$EXT"
test -f /tmp/flarum-anonymous.tar.gz
test ! -e "$BACKUP"
mkdir -p "$BACKUP"
cp -a "$EXT/." "$BACKUP/"
mkdir -p "$BACKUP/forum-composer"
cp -a "$FORUM/composer.json" "$FORUM/composer.lock" "$BACKUP/forum-composer/"

STAGE="$(mktemp -d /tmp/flarum-anonymous-new.XXXXXX)"
tar -xzf /tmp/flarum-anonymous.tar.gz -C "$STAGE"
test -f "$STAGE/composer.json"
test -f "$STAGE/extend.php"
test -f "$STAGE/js/dist/forum.js"
test -f "$STAGE/js/dist/admin.js"
test -f "$STAGE/tools/prepare-rollback.php"

if test -e "$EXT.new"; then mv "$EXT.new" "$BACKUP/previous-new"; fi
if test -e "$EXT.old"; then mv "$EXT.old" "$BACKUP/previous-old"; fi
mv "$STAGE" "$EXT.new"
mv "$EXT" "$EXT.old"
mv "$EXT.new" "$EXT"
printf 'Source backup: %s\n' "$BACKUP"
```

## 服务器更新命令：容器

在宿主 SSH shell 直接复制以下块；命令在现有 PHP 容器内执行。dry run 的预期是只刷新本扩展元数据，运行时 PHP/core 约束和既有扩展版本保持不变。若 Composer 报冲突，保留输出并停止，不添加 `-W`、平台忽略或删除其他包。

```sh
docker exec -i yeedang-bbs-php sh <<'CONTAINER'
set -eu
cd /var/www/flarum
composer validate
php -l extensions/flarum-anonymous/extend.php
php -l extensions/flarum-anonymous/tools/rollback-privacy.php
COMPOSER_ALLOW_SUPERUSER=1 composer update adminlby/flarum-anonymous --dry-run --no-interaction
COMPOSER_ALLOW_SUPERUSER=1 composer update adminlby/flarum-anonymous --no-interaction
COMPOSER_ALLOW_SUPERUSER=1 composer reinstall adminlby/flarum-anonymous --no-interaction
cmp extensions/flarum-anonymous/extend.php vendor/adminlby/flarum-anonymous/extend.php
cmp extensions/flarum-anonymous/js/dist/forum.js vendor/adminlby/flarum-anonymous/js/dist/forum.js
cmp extensions/flarum-anonymous/js/dist/admin.js vendor/adminlby/flarum-anonymous/js/dist/admin.js
php flarum migrate
php flarum cache:clear
php flarum info | grep -i anonymous
LOG="$(ls -1t storage/logs/*.log 2>/dev/null | head -1)"
if test -n "$LOG"; then tail -n 100 "$LOG"; fi
CONTAINER
```

后续仅代码、语言或已编译前端变化，且依赖与声明均无变化时，省略两条 `composer update`，保留 `composer reinstall`、migrate、cache clear 和验证。若部署使用长期队列 worker，按现有运维方式重新加载其代码；本流程不假定其容器名。若 PHP 配置禁止 OPcache 时间戳校验，按现有运维方式重新加载 PHP。

## 更新后验收

1. 首页与 Admin 匿名扩展设置正常；Diff 开关在已启用 Diff 时可操作。
2. 匿名和实名主题、普通回复、匿名帖引用均正常创建；刷新后保持身份。
3. 普通用户的 Post/Discussion API、首末帖、用户主页、正负 author 搜索、SSR/preload 没有作者 user/userId。
4. notification / mention / subscription 站内通知没有自动生成的真实匿名作者姓名或链接；邮件排除。
5. 匿名原帖作者编辑时身份被锁定；直接 PATCH `editHistoryAnonymous=false` 被拒绝。
6. 其他编辑者选择仅影响新增 revision；原帖身份及已有历史不改变。
7. 匿名编辑的 Diff API actor/deletedUser/rollbackedUser 为空；嵌套 include 和 sparse fields 同样受保护。
8. 管理员正常显示仍匿名；显式 reveal 可追溯且不填充普通 User store；Audit 记录查看行为。
9. English / 简中 / 繁中界面正常；最新 Flarum 日志没有新增 ERROR。
10. 单独授权 anonymous.changeIdentity；普通用户、仅编辑 / reveal 权限无法转换。首帖 / 回复双向转换正常，其他编辑者的匿名历史保密。
11. 转换后 SEO HTML source / JSON-LD、Polls API / 投票列表、Split 新主题及 Leaderboard 公开积分与计数无匿名作者关联。
12. 对 [兼容性报告](COMPATIBILITY.md) 中其他第三方路径单独检查；本次验收不代表全部生产插件组合已通过。

## 快速回滚：先保留匿名历史保护，再恢复旧源码

以下命令在宿主 SSH shell 执行。新源码中的工具只允许操作同级精确 `.old` 扩展目录，先备份旧入口与被替换的兼容文件，再附加保护。回滚版本可能将匿名编辑者 / 投票者显示为“用户已删除”；API 不返回真实身份。紧急回滚保守隐藏匿名帖所有编辑者，实名帖的实名历史仍保留；新版转换按钮不属于旧版功能。

```sh
set -eu
EXT=/opt/yeedang-bbs/flarum/extensions/flarum-anonymous
test -d "$EXT.old"
docker exec yeedang-bbs-php php \
  /var/www/flarum/extensions/flarum-anonymous/tools/prepare-rollback.php \
  /var/www/flarum/extensions/flarum-anonymous.old

FAILED="/opt/yeedang-bbs/backups/flarum-anonymous-failed-$(date +%Y%m%d-%H%M%S)"
test ! -e "$FAILED"
mv "$EXT" "$FAILED"
mv "$EXT.old" "$EXT"

docker exec -i yeedang-bbs-php sh <<'CONTAINER'
set -eu
cd /var/www/flarum
COMPOSER_ALLOW_SUPERUSER=1 composer reinstall adminlby/flarum-anonymous --no-interaction
php flarum cache:clear
php flarum info | grep -i anonymous
LOG="$(ls -1t storage/logs/*.log 2>/dev/null | head -1)"
if test -n "$LOG"; then tail -n 100 "$LOG"; fi
CONTAINER
```

不要运行 `migrate:reset`，不要删除 `anonymous_edit_history` 或 `last_edit_is_anonymous`。本流程保留数据库中新版本写入的匿名决策，恢复旧功能并继续保护这些历史。若需完整恢复数据库，应使用部署前数据库备份并评估期间的新投稿，不能把源码回滚当作数据库恢复。
