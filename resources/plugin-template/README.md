# 插件模板（demo_hello）

最小可用插件：群内 `/hello 名字` → 记住名字并回问候。改这个模板就能做出自己的功能。

## 目录结构

```
demo_hello/
  manifest.json      # slug / name / version / author / description
  HelloFeature.php   # 功能类，继承 BaseCustomFeature
  README.md          # 这个文件（可选，不会被执行）
```

## 三条硬规则（审核会拦）

1. **slug 与命名空间必须对上**：`slug = demo_hello` → 命名空间 `Modules\Telegram\Plugins\DemoHello`。
   目录名也要叫 `DemoHello`（打包成 zip 时顶层文件夹名随意，安装器按 slug 重新落盘）。
2. **只允许 `.php / .json / .md / .txt`**，单文件 ≤ 512KB，解压后 ≤ 5MB。
3. **禁止**：`eval`、执行系统命令（`exec`/`shell_exec`/`system` 等）、动态 `include $x`、
   读写配置与密钥文件、访问本机与内网地址、裸 SQL、改表结构、注册路由。
   插件只能通过平台提供的模型与 `FeatureContext` 干活。

## 打包与安装

```bash
cd demo_hello            # manifest.json 所在目录
zip -r demo_hello.zip .   # 或者把整个文件夹压进去，安装器能自动剥一层
```

后台「插件管理 → 上传插件」选这个 zip：
1. 脚本审核（语法、危险函数、路径、命名空间）；
2. AI 审核（后台「审核设置」开关打开时才参与，被拒可强制安装）；
3. 通过后自动同步到「功能列表」，去那里绑定机器人 / 群，命令即可用。

## 能用的能力

| 代码 | 作用 |
| --- | --- |
| `$context->args` | 命令参数数组 |
| `$context->value('name')` | 优先取实参，其次取已存数据 |
| `$context->config('greeting')` | 读后台配置（`configSchema` 声明的字段） |
| `$this->putData($context, [])` | 按「群 / 机器人」作用域持久化 |
| `$this->data($context)` | 读回已存数据 |
| `$this->reply($text)` / `$this->fail($msg)` | 回复 / 报错 |

需要调三方接口时，用平台的 `callEndpoint()`（见 `Drivers/Custom` 里的内置功能），
不要在插件里直连上游——接口配置、鉴权、响应归一都在平台侧统一处理。

## 想要付费功能？

覆写 `accessLevel()` 返回 `'paid'`：归属账号必须处于有效套餐期内才能执行。
代码里声明的只是首次同步的默认值，之后以后台「功能列表」的计费开关为准。
