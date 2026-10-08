# TgGroupCheckin — XBoard Telegram 签到插件

版本：1.0.2 · 作者：www.xhj.me · 协议：[MIT](LICENSE)

扩展 XBoard 主 Telegram 机器人，支持私聊、群聊签到和随机流量奖励。不需另建机器人或使用 AI 接口。

## 安装

1. 在 XBoard 配置主 Bot Token / Webhook，启用 `telegram` 插件。
2. 将 `TgGroupCheckin` 目录打包为 ZIP，在后台插件页上传、安装并启用「Telegram 群签到」。压缩包内保留 `TgGroupCheckin/config.json`、`Plugin.php`。
3. 配置签到开关、触发关键词、奖励概率及流量范围。
4. 将主机器人加入群，并确保能收到普通签到文本。
5. 升级后重启使用中的 Octane 等常驻服务。

插件不新增数据库表，奖励直接增加用户 `transfer_enable` 额度。

## 使用

先私聊主机器人绑定账号，订阅链接不要公开到群：

```text
/bind 自己的订阅链接
```

随后在允许的私聊或群内发送 `签到`。关键词完全匹配并忽略首尾空格，`/签到`、`签到啦` 不匹配默认关键词。

## 配置

| 配置键 | 默认值 | 说明 |
|---|---|---|
| enable_checkin | true | 总开关 |
| enable_private_checkin | true | 私聊签到 |
| enable_group_checkin | true | 群内签到 |
| checkin_keyword | 签到 | 触发文本 |
| reward_probability | 30 | 中奖概率 0–100%；100 表示有效签到必中奖 |
| reward_traffic_min_mb | 50 | 随机流量下限 |
| reward_traffic_max_mb | 200 | 随机流量上限 |
| daily_once | true | 同用户每日一次，私聊与所有群共享次数 |
| reply_not_bound | 配置页默认文案 | 未绑定提示 |
| reply_already_checked | 配置页默认文案 | 已签到提示 |
| reply_miss | 配置页默认文案 | 未中奖提示 |
| reply_success | 含 {traffic} 的默认文案 | `{traffic}` 替换为实际流量 |

MB 按 1048576 字节计算；上下限倒置时自动交换。未中奖也占当天次数。
每日记录保存在缓存，按运行环境日期判断并在当天结束时过期；清空缓存可能使当天允许再次签到。

## 排查与限制

- 无回复：检查关键词、私聊/群开关、插件启用状态、Webhook 和机器人接收普通消息的权限。
- 提示未绑定：用户需私聊当前主机器人绑定账号。
- 没有中奖：默认概率 30%，签到不保证奖励。
- 奖励写入失败：查看宿主 `storage/logs`。当前先标记签到后写奖励，失败后可能仍占当天次数。
- 当前没有事务锁或原子签到限制，不应视作并发刷奖励的严格防护。
- 不提供群 ID 白名单、排行榜、补签或连续签到奖励。

## 开源与数据

读取 Telegram 账号绑定，修改绑定用户流量并保存缓存签到状态，不向 AI 服务发送文本。
允许使用、修改、商业使用和再分发，须保留 [MIT LICENSE](LICENSE) 中版权与许可声明。
XBoard 与其他依赖遵循各自协议；本插件不是 Telegram 官方产品。部署者应自行配置流量额度、权限和奖励规则。
