# 生产插件版本兼容性核查

依据用户提供的 `composer show --direct`；未读取生产服务器。此前取得了 28 个第三方包对应版本的公开源码供检查。本次在本机实际通过 Composer 安装并组合运行 core `2.0.0-rc.8`、测试所需官方扩展、**Diff 2.0.0、SEO 4.0.0-beta.9、Polls 3.0.0-rc.4、Split 2.0.0-beta.4、Leaderboard 2.1.0**。这仍不是全生产插件组合验收。

已有具体身份路径的四个插件本次均补上后端适配，并与 Diff、Tags、Mentions、Subscriptions、Audit 一起执行身份转换回归；Polls 另有真实浏览器投票列表验证。整份列表仍不能统一标为“全部兼容”。

| 包 / 用户版本 | 已识别路径 | 本次状态 |
| --- | --- | --- |
| `huseyinfiliz/flarum-diff 2.0.0` | 原始 revision 0 和编辑记录 actor，删除 / 回滚操作账号、历史 API 嵌套 include | **已适配并实际测试**：正常读取匿名、创建时决定 revision 身份、匿名原帖作者强制匿名、显式 reveal + Audit |
| `fof/seo 4.0.0-beta.9` | JSON-LD 绕过 API getter 直接读取 Eloquent 作者 | **已适配并实际测试**：官方 PreparingPageMeta 事件重建匿名主题 / 回复作者节点，保留正文和媒体 schema，作者节点不含账号 URL |
| `fof/polls 3.0.0-rc.4` | 独立 Poll.user 及作者在关联投票里的 votes.user | **已适配并实际测试**：按关联 Post 当前状态隐藏创建者及作者自身投票，其他投票者保持实名；独立全站投票不因没有关联帖子而强制匿名 |
| `fof/split 2.0.0-beta.4` | 新 Discussion starter 未继承起始帖匿名状态 | **已适配并实际测试**：官方 DiscussionWasSplit 事件写入首帖身份，创建响应和后续 API 继续保护；转换为实名后恢复身份 |
| `huseyinfiliz/leaderboard 2.1.0` | 真实计数 / 基于匿名投稿的积分变化关联账号 | **已适配并实际测试**：公开排名、profile leaderboardPoints 仅统计公开实名来源，匿名 / 私密 / 隐藏帖子及主题的积分不公开；公开计数不使用真实累计值，私有原账本 / totals 保留 |
| `fof/drafts 2.0.0` | `Console/PublishDrafts.php` 定时回复只传 `content`；没有传草稿的 `isAnonymous`，新主题才合并 `extra`。前端保存 / 恢复匿名 attrs 尚未完成组合测试 | **投稿身份保存与定时发布未适配**；不能认定草稿中选择匿名会在定时回复中保留 |
| `fof/follow-tags 2.0.0-beta.7` | 使用独立通知类型及邮件模板 | **功能未完整适配**；未知匿名通知会被本扩展保护层抑制，不能承诺全部标签通知可送达 |
| `flarum/realtime 2.0.0-rc.8` | 额外即时推送路径 | 用户已禁用；未在本次生产组合验证中启用 |

上述适配通过本机真实 HTTP/JSON/SSR 和数据库检查，尚未连接生产论坛。转换匿名 / 实名不会重写真实用户 ID。所有适配可选；禁用对应第三方扩展时不加载其 API 类，也不要求生产额外安装测试包。其他扩展发放的徽章、上传文件 owner 和独立事件 payload 不自动等同于已验证的积分来源。

## 其余包的状态

核心 JSON API、搜索 / 个人页、SSR、Mentions / Subscriptions 站内通知、Likes、Audit 和 Tags 的上述组合有运行测试。既有投稿邮件测试保留，本次身份转换按要求排除邮件。其他官方扩展如下，暂不能给出完整组合通过结论：

| 包 / 用户版本 | 状态 |
| --- | --- |
| `flarum/approval`、`flags`、`lock`、`sticky`、`suspend`、`nicknames`，均 `2.0.0-rc.8` | 需验证审批、标记、自动事件及显示名交互；本次没有同时启用全部运行 |
| `flarum/gdpr 2.0.0-rc.8` | 需独立验证导出、账号删除与原始作者 / 编辑者 ID 快照的保留策略 |
| `flarum/messages 2.0.0-rc.8` | 私信的独立资源、可见性、通知尚未做匿名组合验证 |
| `flarum/statistics`、`extension-manager`，均 `2.0.0-rc.8` | 管理工具组合尚未跑；不能从后台名称推定匿名作者没有其他输出 |
| `flarum/bbcode`、`markdown`、`emoji`，均 `2.0.0-rc.8` | BBCode 引用有 API 测试；其余格式及 Emoji 组合未跑 |
| `flarum/lang-english 2.0.0-rc.8`、简中 `2.x-dev 4bd52c0`、繁中 `2.0.7` | 本扩展三套 locale 完整检查；不代表语言包自身的全部 UI 已测试 |

以下第三方版本已定位或取得源码，但没有完成该生产组合的运行验收；没有标“有具体泄露路径”也不等于安全兼容：

| 包 | 用户版本 | 需补充验证的交互 |
| --- | --- | --- |
| `ianm/twofactor` | `2.0.0-beta.6` | 登录 / reveal 认证及会话 |
| `ekumanov/flarum-ext-forum-widgets` | `1.6.7` | 在线 / 最新动态 / 统计是否直接输出账号关联 |
| `ernestdefoe/mobile-tab` | `2.0.1` | 移动端发帖入口是否走核心 Composer |
| `ffans/community-notes` | `2.0.0-beta.1` | 独立 notes 作者、评分 API 与匿名帖关联 |
| `fof/anti-spam` | `2.0.0-rc.5` | 管理反垃圾数据及作者批量操作 |
| `fof/badges` | `2.0.0-beta.2` | 按投稿授予徽章时的账号活动关联 |
| `fof/bookmarks` | `2.0.0-rc.3` | 收藏页及其嵌套讨论资源 |
| `fof/discussion-templates` | `2.0.0-beta.1` | 模板打开 Composer 前的身份选择 |
| `fof/discussion-views` | `2.0.0-rc.2` | 计数及访客数据输出 |
| `fof/disposable-emails` | `2.0.0-beta.1` | 注册 / 匿名权限要求 |
| `fof/first-post-approval` | `2.0.0-beta.1` | 真实 user_id 下的审核与发布后匿名 |
| `fof/formatting` | `2.0.0-beta.2` | 每个启用 TextFormatter plugin 的自动内容 |
| `fof/linguist` | `2.0.0-beta.1` | 自定义翻译对匿名标签及三语 key 的覆盖 |
| `fof/links` | `2.0.0-rc.3` | 导航发帖入口 |
| `fof/merge-discussions` | `2.0.0-beta.4` | 合并后首末帖、主题 starter 与事件内容 |
| `fof/moderator-notes` | `2.0.0-rc.1` | 管理备注权限；不能作为公开匿名资料输出 |
| `fof/reactions` | `2.0.0-beta.9` | 独立通知、邮件、积分联动 |
| `fof/sitemap` | `3.0.0-beta.6` | 站点地图是否关联用户页 / 匿名活动 |
| `fof/terms` | `2.0.0-rc.1` | 发帖前条款拦截与身份弹窗取消 |
| `fof/upload` | `2.0.0` | 独立 file owner API、链接、附件元数据与个人文件页 |
| `zerosonesfun/flarum-preview` | `2.0.9` | 预览 Composer attrs 和匿名 avatar |

源码参考为发行包本身；例如 [Diff 2.0.0](https://github.com/huseyinfiliz/flarum-diff/tree/d73d0ac7b0d217b09769270891421fb7cdca8543)、[FoF SEO](https://github.com/FriendsOfFlarum/seo)、[FoF Split](https://github.com/FriendsOfFlarum/split)、[FoF Polls](https://github.com/FriendsOfFlarum/polls)、[Leaderboard](https://github.com/huseyinfiliz/leaderboard)。仓库后续版本可能改变接口，不能把本次版本结论直接套用到新版本。
