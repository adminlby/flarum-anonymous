# rc.8 API 研究记录

研究对象为实际 Composer 安装的 `flarum/core 2.0.0-rc.8`，以及官方 framework 的相同 tag；core commit 为 `f49072ab828fa19e92460b9ffd707d41ca9aaa04`。实现依据本地源码，不沿用 Flarum 1.x Serializer / gambit / Admin extensionData 示例。

| rc.8 源码 / 接口 | 观察和实现 |
| --- | --- |
| `src/Api/Resource/PostResource.php` | 核心 POST 创建 `CommentPost`，通过 Resource 的 `saving()` 分发 `Post\Event\Saving`；使用 `Extend\ApiResource` 增加创建字段并改写 relationships 的 getter |
| `src/Api/Resource/DiscussionResource.php::saveModel()` | 首帖是嵌套 Post API 创建，传 `Context::internal('isFirstPost')`，只转发 content；用 request 容器内的 WeakMap 记录该内部标记，从持久化 Discussion 继承匿名状态 |
| `Api\Schema\Boolean` / `Api\Context` | `isAnonymous` 仅 `writableOnCreate()`，严格接受 JSON boolean；编辑请求及 Eloquent 保存同时约束身份不可变 |
| `Post::newFromBuilder()` / RegisteredTypesScope | Post 有具体子类型，Eloquent saving 事件按子类型命名；使用 Illuminate Dispatcher 的官方 wildcard listener 覆盖所有类型，避免只监听 Post 基类漏掉 CommentPost |
| 核心 Saving 与自动事件帖子 | 请求 WeakMap 同时区分明确选择身份的用户投稿与自动事件；自动改名事件继承匿名，用户主动选择实名的自定义类型仍保持实名 |
| `Extend\Policy` / `User::assertCan()` | 创建使用核心身份和权限体系；真实 `user_id` 保持限流、编辑权限有效。Reveal 通过 PostRepository 可见性检查后再授权 |
| `ApiResource::field()` / Resource `getValue()` | 匿名 relationship 返回 null；实名关系保留核心 batched loading，避免把常规关系都变成单条 SQL |
| API 的 inverse / linkage-only Post | 精简模型可能只有 id；缺失匿名字段时按持久化状态判定，不为模型安装 false 默认属性，以免把“未加载”当作“实名” |
| `Extend\SearchDriver(DatabaseSearchDriver::class)` | rc.8 的作者查询是注册 Filter；用 `replaceFilter()` 保护 posts / discussions，包括 `filter[-author]` 负查询 |
| UserResource searchable index | `Schema\Sort` 在 searchable 路径不会决定数据库排序；同时提供 `addMutator(UserSearcher::class)`，使 commentCount / discussionCount 排序也使用实名活动 |
| `Schema\Integer::countRelation()` | 使用独立 aggregate alias，批量取得用户公开计数，避免直接读取含匿名活动的核心持久化计数 |
| `NotificationSyncer` / `NotificationMailer` | 容器绑定替换为继承核心实现的保护层，仍使用核心 sync / delete / restore / delivery。匿名蓝图取消发送者关系，并使用 allowlist 数据与独立邮件模板 |
| Mentions parse / render / unparse | 官方 render hook 只修显示不足以保护搜索；saving 时同时规范化 POSTMENTION XML 的 displayname 和原始 token，render / unparse hooks 保护已有引用输出 |
| `Extend\View::extendNamespace()` | 通过官方视图命名空间优先级提供 SSR discussion 视图；原 core 视图文件不变 |
| `Flarum\Audit\Extend\Audit::listen()` | rc.8 有公开 extender，可记录 `AuthorRevealed`；用官方 ModelVisibility scoper 限制无 reveal 权限的审计查看者 |
| `forum/states/ComposerState.tsx::load()` | Composer 懒加载并异步显示；先解析 composer 类、选择身份，再调用原 load / show。支持核心 composer 子类 |
| `forum/components/IndexSidebar.tsx::newDiscussionAction()` | rc.8 原实现未返回内部 load promise；扩展将完整流程连接到返回 promise，使取消弹窗被现有按钮 catch 处理 |
| Composer attrs | core Composer 每次 view 重建组件 attrs；切换身份必须同时写入 `composer.body.attrs`，否则按钮虽点击但下次 redraw 恢复原值 |
| Flarum frontend `Model.save()` | 补充新 posts / discussions 的身份选择；已有资源 save 完全不触发创建选择 |
| `flarum/common/extend` + registry `onLoad()` | lazy Component 使用模块路径扩展；PostControls 是普通对象，通过 registry onLoad 后扩展其方法 |
| `admin/app.registry.for()` | rc.8 的 Admin 设置与权限登记使用 registry，未使用已移除的 extensionData |
| Less / CSS | 使用 rc.8 的 CSS custom properties；未依赖旧 core Less 色彩变量 |
| 核心 `Application.request()` | 浏览器 PATCH 使用 POST 加 `X-HTTP-Method-Override`；真实浏览器编辑测试按这条实际请求路径检查 JSON 响应 |
| 核心 extension CLI / migrator | 真正执行 enable / disable / migrate / migrate:reset / cache:clear，验证 down/up 后 core 内容与 ID 完整保留 |
| `Extension::calculateDependencies()` | rc.8 读取 `extra.flarum-extension.optional-dependencies` 的包名数组；顶层 `flarum-optional-dependencies` 不会排序。已修正声明并验证本扩展在 Diff 后应用 Resource mutator |
| `MigrateCommand` / `syncExtensionOrder()` | 生产已有扩展无需重新 enable；migrate 会按新的可选依赖声明持久化启用顺序。testing manager 的显式启用数组不会自动排序，测试采用与生产相同的依赖顺序 |
| Diff `2.0.0` 原生 Resource / Revised listener | 使用已安装发行包的 `DiffResource`、`post_edit_histories` 模型及 Revised 创建事件；修改 API getter，不修改 Diff 代码；身份元数据保存在本扩展独立表 |
| rc.8 Post 子树缓存 | 匿名资源没有 author User freshness；DiffListState 的 redrawList 通过扩展更新对应 CommentPost 子树检查，历史异步加载后正确重绘 |
| 编辑 API schema | editHistoryAnonymous 只 `writableOnUpdate()`，setter 不写 core 无关字段；Post Saving 决定本次新增记录，Diff created listener 保存私有快照；canChooseEditHistoryIdentity 尊重 Diff mainPostOnly |
| 管理身份转换 | 独立 Routes POST + Policy，在可见 Post 上授权并在事务锁后复查；普通 API resource 的 isAnonymous 保持创建时可写，管理转换不发核心投稿 / 编辑事件 |
| Diff 压缩归档 | 使用 2.0.0 的 DiffArchiveRepository getContents/setContents 读取 post_edit_histories_archive；引用替换采用 compare-and-update，检测并发变化后整笔回滚 |
| SEO / Split | 使用指定版本公开 PreparingPageMeta / DiscussionWasSplit 事件，不改其 Eloquent 真实作者和核心文件 |
| Polls / Vote | 正式 ApiResource extender 按关联 Post 保护 Poll.user / PollVote.user；registry onLoad 适配懒加载 ListVotersModal |
| Leaderboard | 继承原生 controller 保留授权、路由和分页；Schema Integer countRelation 使用字符串 column 和每个 reason 独立 relationship，使 rc.8 EloquentBuffer 批量缓存正确 |

四个迁移只增加扩展列、索引、权限和私有编辑元数据表。扩展被禁用时，核心能够忽略这些额外列并读取原始帖子；reset 时按反向顺序删除扩展字段。core 用户删除时的 NULL 外键行为通过额外的原始作者 / 编辑者数字快照兼容。reset 会失去匿名决策，不能用于生产代码回滚。
