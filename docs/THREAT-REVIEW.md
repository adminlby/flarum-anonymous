# 匿名身份泄露 threat review

审查目标是阻止未获得 `anonymous.reveal` 的用户通过系统产生的 API、页面、搜索或通知作者信息反查匿名作者。测试使用带有唯一用户名、邮件和 IP 标记的真实数据库作者，直接检查 JSON:API 文档与邮件正文，包含 guest、普通读者、作者本人、Administrator、仅有 reveal 权限的管理用户。

## 已检查的泄露路径

| 路径 / 威胁 | 防护与验证 |
| --- | --- |
| Post user / editor / hidden actor relationship 与 IP | 所有匿名 Post getter 返回 null；包括作者本人及管理员的普通读取。多层 include 与 sparse fields 的 JSON 测试验证 linkage 和 included 都没有真实作者 |
| Discussion starter / lastPostedUser / 首末帖 / 搜索命中帖 | Discussion 持久化匿名标记独立于首帖是否存在；lastPostedUser 按实际末帖匿名状态屏蔽；末帖引用缺失时停止输出未能验证的旧作者 ID；嵌套 Posts 共享保护 |
| 精简 inverse 模型缺少 is_anonymous | 不装 false 模型默认值；Post / Discussion 只拿到 id 时查持久化状态，记录已经删除时隐藏无法验证的缓存作者，避免遗漏保护 |
| author filter / 用户帖子列表 | 正作者查询排除匿名；负作者查询始终包含可见匿名结果，不能用逐个排除识别作者 |
| 公开活动计数和排序 | commentCount / discussionCount 只计实名活动，排序使用同一公开 aggregate；测试将真实作者核心计数改为 900000 仍不改变公开排名 |
| 去重参与人数 | 隐藏 API participantCount，避免匿名回复前后独立用户数是否增加形成身份关联 oracle；数据库字段保持原值 |
| SSR / preload | SSR 视图使用匿名标签与头像；解析真实 HTTP HTML 内的 flarum-json-payload，检查匿名 JSON 关系没有作者 |
| 自己的匿名帖子 | isMine 仅由当前 actor 比较产生，不填充真实 User relationship；关闭 own hint 只影响显示，不改变匿名保护 |
| 编辑 / 类型切换 / 参数篡改 | 创建字段只接受布尔值；编辑不可更改匿名状态，Eloquent 子类型保存也拦截更改；其他帖子类型通过核心 Saving 事件检查独立设置和权限 |
| 越权管理转换、仅 reveal / 编辑权限、匿名开关绕过 | 独立 POST 要求 anonymous.changeIdentity、登录及帖子可见性；事务加锁后再次授权，默认仅 Admin。普通 PATCH 即使管理员也不能切换；开关关闭时只有获授权的管理转换可继续 |
| 转换中途失败及重复请求 | 首帖、主题、同作者事件、历史身份、原文 / 压缩引用及 Audit 同一事务，故障全部回滚；相同状态幂等且不重复审计。没有 PostCreated / PostRevised 事件，不发送通知或邮件 |
| 转回实名误公开其他匿名编辑者 | 只恢复原作者本人及 revision 0 的身份，其他编辑者独立匿名元数据保留；确认弹窗说明原作者账号将公开 |
| Diff 历史 actor / revision 0 / 删除 / 回滚操作者 | 可选 Diff 2.0.0 Resource 的匿名关系返回 null；旧匿名帖的 revision 0 与原作者后续记录强制匿名；公开编辑记录里的原匿名作者删除 / 回滚关系也隐藏 |
| 编辑身份通过 API 恢复实名 | editHistoryAnonymous 仅更新内容时决定新增记录；匿名原帖作者 false 被服务器拒绝；无 Diff / 开关关闭 / 未记录回复不提供选择；原帖 is_anonymous 和旧记录不变 |
| Diff 中途失败 / 记录不完整 | 保存实名帖的匿名编辑前，私有表先记录匿名 reservation；无匹配身份行时该 actor 的记录保守匿名，避免 native Diff 已写 actor 而元数据写入失败后恢复实名 |
| 查看真实编辑者和审计反查 | 独立 POST 要求 reveal + viewEditHistory + 帖子可见；响应 no-store，不进入 User store；Audit 有私有 editor ID，其他审计查看者不能读取关联匿名编辑的日志 |
| 回复 / 讨论嵌套历史权限 | 对 Post.diff 的可见性和 eager scope 使用官方 Resource 字段扩展，guest 无法通过 include 绕过历史权限；二十条 sparse revision 测试确认私有元数据批量加载 |
| 代码回滚重新暴露新增匿名编辑 | 独立 rollback-privacy guard 可在不加载新版 Anonymous 后端时隐藏 Diff actor / last editedUser，准备工具限制精确 .old 同级目录且幂等；保留新数据库元数据 |
| 回滚丢失第三方作者保护 | prepare 工具保留四个 Compatibility 适配及通知状态判断；独立测试不启用新版 Anonymous 后端，仍隐藏 Poll creator/voter、SEO author 和匿名来源积分；拒绝同级目录外目标及子路径符号链接 |
| 首帖内部请求被误认 | 使用 rc.8 的内部 isFirstPost 标记；删除首帖后新普通回复不会被误判为主题首帖或强制匿名 |
| Mention / 引用还原作者名称 | 入库前清理自动生成的 POSTMENTION displayname 和原文 token；同时保护渲染及 unparse。存储 XML、编辑 API、HTML、搜索全检查 |
| 已实名发布后转匿名，旧引用 / 历史残留真名 | 转换同步所有结构化 POSTMENTION displayname/token，包括其他帖子的原文、Diff 明文与官方压缩归档；不按文本替换任意正文。无法解压或并发内容更新导致整笔失败，不留下部分匿名状态 |
| 通知发送者、subject、payload | 匿名 blueprint 的发送者为 null；数据 allowlist；匿名 subject 的嵌套关系继续保护。检查实际数据库通知记录和接收者 JSON |
| 老 notification 行 / 老队列蓝图 | API 按源 Post 状态再屏蔽原 fromUser 和 payload；邮件发送器在送达时再次加载数据库并替换蓝图，测试真实渲染后的 subject / text / HTML；源帖子已删除时停止发送，旧通知也不输出无法验证的缓存作者 |
| 实名 / 匿名转换后的站内通知标记过期 | 活源帖优先于遗留 isAnonymous；转匿名不输出旧 sender/payload，转实名只对已知核心类型恢复来源作者，不为未知 sender 类型伪造账号 |
| SEO JSON-LD 绕过资源层 | 官方 PreparingPageMeta 事件将匿名主题及评论 author 替换为本地化 Person 名称，无用户页 URL；实际 HTTP HTML 检查第一帖和匿名回复 |
| Polls 独立作者 / 原作者投票反查 | Poll 与 PollVote 资源直接按关联帖子身份判断，创建者和作者自己的投票 user 为 null，UI 用匿名图标；转换后重新加载真实 API 验证恢复 / 再匿名 |
| Split 新主题首帖失去匿名 | 官方 DiscussionWasSplit 事件继承首帖标记，拆分后 API 及 Audit 包含 original/new discussion ID 的查询均受保护 |
| Leaderboard 公开计数 / 分数 oracle | 原生 controller 替换与官方 API 计数缓冲仅聚合公开实名来源；不改变私有账本，测试真实数据库累计值 900000 不影响公开结果 |
| 未知通知类型 | 匿名来源的未适配类型停止投递；不会把不认识的 payload / 邮件模板直接发送给用户 |
| 匿名主题改名生成事件帖子 | 发起人的事件帖继承匿名状态，阻止 discussionRenamed 显示真实 User |
| Reveal 未授权、不可见帖子、缓存与 CSRF | POST 路由先认证与可见性检查再授权，响应 private / no-store；普通用户和作者调用失败；私人帖不能枚举；真实浏览器缺少 CSRF 被拒绝 |
| 管理员 reveal 后 store 污染 | 独立响应资源，仅弹窗局部状态保存，关闭即清除；浏览器确认 reveal 后 post.user() 仍为空、帖子旁仍匿名 |
| Audit 反查 | 有 reveal 权限者保留追溯；其他审计查看者过滤 reveal 及引用匿名帖子 / 主题的日志，包含已经取消类型注册的历史匿名帖，不能经 actor_id / payload / IP / author search 绕过 |
| 删除账号后追溯丢失 | 不可编辑的私有 anonymous_author_id 快照保留原 ID；普通 API 不暴露，reveal 返回原 ID 及 deleted=true |
| 限流、权限、禁用及迁移 | 真实 user_id 保留，核心 flood control 仍适用；独立开关和权限在服务器检查。实际 CLI 禁用 / 启用 / down/up 验证 core 数据完整 |

## 审查结论及边界

对测试覆盖的 rc.8 核心、默认 DatabaseSearchDriver、Mentions / Subscriptions 站内通知、Audit、SSR、Diff 2.0.0，以及四个指定版本的 SEO / Polls / Split / Leaderboard 路径，没有发现未经授权的真实作者 / 匿名编辑者 relationship、ID、自动生成姓名或 IP 输出。既有投稿邮件测试保留，本次转换明确不发送通知，邮件按用户要求排除。完整 JSON 包含文档、负过滤、公开计数排序和真实浏览器请求均有检查，结果见测试报告。

普通读取即使有 reveal 权限也保持匿名。追溯是有意授权的独立操作，其日志包含敏感 ID，需要按论坛管理员数据保护流程管理。源码自身没有访问生产论坛或发布代码。

以下边界需要明确保留：

- 作者在正文、标题或附件中主动提供身份、外链、署名不会被语义删除；发布时间、内容及写作风格也不能提供抗流量分析保证。
- 数据库、备份、服务器访问日志及 reveal 管理权限能够访问真实身份。这是管理追溯需求的一部分。
- 未验证的第三方 Resource、独立搜索驱动、Webhook、导出、推送、直接邮件发送及自定义事件 payload，必须独立审查。使用受保护核心资源与 Saving / 通知流程是接入要求。
- 本次补齐并组合测试 SEO、Polls、Split、Leaderboard 的指定路径；Drafts 定时回复匿名选择、Follow Tags 独立通知及其他未验证第三方输出仍不能统一承诺兼容。版本与源代码证据见 [COMPATIBILITY.md](COMPATIBILITY.md)。
- 内容此前实名公开，或已送达其他浏览器、缓存、索引、Webhook 的身份无法召回。转换后请求按当前服务器状态保护；确认后刷新操作者页面不能替其他用户清空已获得的数据。用户手工输入的姓名或非结构化引用不会被自动删除。
- 完全禁用 / 移除扩展会让核心按真实 user_id 正常显示帖子。关闭 Admin 总开关仍保留匿名保护；需要保留历史匿名性的站点使用该开关。
- migrate:reset / Admin 卸载会丢失匿名标记、编辑身份及私有 ID 快照；生产代码回滚保留这些元数据并使用专用 guard。测试 down/up 使用仅限独立测试站的快照，不存在生产自动恢复承诺。

没有修改核心 / vendor、没有伪造 User、没有通过 CSS 隐藏真实 API 数据、没有依靠仅前端的作者替换完成匿名保护。
