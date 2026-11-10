# 商户三件套示例插件（merchant_demo）

**路线 B 教学示例**：与内部版 `Drivers/Custom/Merchant/` 的
`BindMerchant` / `GetMerchantBalance` / `QueryOrder` 功能同构，一个插件装回三个命令。
安装与审核规则见 [docs/feature-dev.md](../../docs/feature-dev.md)。

```
merchant_demo/
  manifest.json          # slug=merchant_demo → 命名空间 Modules\Telegram\Plugins\MerchantDemo
  MerchantDemoFeature.php
  README.md
```

## 命令

| 命令 | 作用 | 对应内部版 |
| --- | --- | --- |
| `/bmd 商户号` | 绑定/查看本群商户号（先向上游确认存在） | `BindMerchant` |
| `/ymd` | 查余额 | `GetMerchantBalance` |
| `/cxm 订单号` | 查订单（订单号追加到路径末尾） | `QueryOrder` |

## 与内部版的差异（对比着看）

| 差异点 | 内部版 | 本插件 |
| --- | --- | --- |
| 命名空间 | `...\Drivers\Custom\Merchant` | `Modules\Telegram\Plugins\MerchantDemo`（slug 转驼峰） |
| 代码组织 | 三个类 + 公共基类 `AbstractMerchantFeature` | 一个类三个命令，`handle()` 里按 `$ctx->command` 分派 |
| 商户号存储 | 写 `merchant_routes` 服务（上游回调按它反查） | `putData()` 自存自取（功能数据表） |
| 多语言 | `LocalizedReply` trait + 语言包 | 自带文案；插件也可以 `use LocalizedReply`，但 key 要自己补 |
| 接口 code | `merchant.*`（内置注册） | `demo.*`（**插件必须用自己的前缀**，覆盖内置 code 会抢走绑定功能） |
| 上游 | 「机器人 → 可绑定上游」决定 | 相同（代码不写死上游） |

## 使用前配置

1. 后台「插件管理 → 上传插件」选 zip（打包：在 manifest 所在目录 `zip -r merchant_demo.zip .`）；
2. 「功能列表」找到 **商户示例（插件）**，绑定机器人 / 群；
3. 上游接口默认路径是占位（`api/merchant/check` 等），到「上游接口配置」里按你的上游改
   path；没配上游时命令会明确报错（fail-closed）；
4. 群里 `/bmd <商户号>` → `/ymd` → `/cxm <订单号>`。
