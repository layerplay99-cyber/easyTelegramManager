<template>
  <div class="p-4 max-w-5xl space-y-4">
    <el-alert type="info" :closable="false" show-icon>
      这里汇总了系统全部功能与命令。带 <code>/</code> 的是群里直接发的斜杠命令；「上游回调」类由第三方主动推送触发。
    </el-alert>

    <!-- 三方回调类 -->
    <el-card shadow="never">
      <template #header>
        <span class="font-semibold">一、三方回调类（第三方主动推送）</span>
      </template>
      <el-table :data="callbacks" size="default" border>
        <el-table-column prop="name" label="功能" width="200" />
        <el-table-column prop="trigger" label="触发方式" width="180" />
        <el-table-column prop="desc" label="说明" />
      </el-table>
    </el-card>

    <!-- 商户命令 -->
    <el-card shadow="never">
      <template #header>
        <span class="font-semibold">二、商户命令（群里发斜杠命令）</span>
      </template>
      <el-table :data="merchantCmds" size="default" border>
        <el-table-column label="命令" width="200">
          <template #default="{ row }"><code>{{ row.cmd }}</code></template>
        </el-table-column>
        <el-table-column prop="desc" label="说明" />
      </el-table>
    </el-card>

    <!-- 钱包命令 -->
    <el-card shadow="never">
      <template #header>
        <span class="font-semibold">三、钱包命令（群里发斜杠命令）</span>
      </template>
      <el-table :data="walletCmds" size="default" border>
        <el-table-column label="命令" width="200">
          <template #default="{ row }"><code>{{ row.cmd }}</code></template>
        </el-table-column>
        <el-table-column prop="desc" label="说明" />
      </el-table>
    </el-card>

    <!-- 识图 -->
    <el-card shadow="never">
      <template #header>
        <span class="font-semibold">四、识图</span>
      </template>
      <el-table :data="ocrFeatures" size="default" border>
        <el-table-column prop="name" label="功能" width="200" />
        <el-table-column prop="trigger" label="触发方式" width="180" />
        <el-table-column prop="desc" label="说明" />
      </el-table>
    </el-card>

    <!-- 真人功能 -->
    <el-card shadow="never">
      <template #header>
        <span class="font-semibold">五、真人号功能</span>
      </template>
      <el-table :data="realmanFeatures" size="default" border>
        <el-table-column prop="name" label="功能" width="200" />
        <el-table-column prop="desc" label="说明" />
      </el-table>
    </el-card>

    <!-- 其他执行器 -->
    <el-card shadow="never">
      <template #header>
        <span class="font-semibold">六、其他执行器</span>
      </template>
      <el-table :data="otherDrivers" size="default" border>
        <el-table-column prop="name" label="执行器" width="200" />
        <el-table-column prop="desc" label="说明" />
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
const callbacks = [
  { name: '接收三方推送', trigger: '上游 POST 回调', desc: '通用回调入口，把上游推送的报文转成群消息。入站统一走 X-Access-Key 头签名验签。' },
  { name: '交易通知 → 群内按钮', trigger: '上游 POST 回调', desc: '回调转成带「确认 / 驳回」按钮的消息，配合「交互按钮点击处理」使用。' },
  { name: '交互按钮点击处理', trigger: '用户点按钮', desc: '处理按钮点击，按 branches 配置执行确认 / 驳回分支（调用三方接口、回复等）。' },
]

const merchantCmds = [
  { cmd: '/bd 商户号', desc: '绑定 / 查看本群的商户号（后续查询、交易都以此为准）' },
  { cmd: '/cx 订单ID', desc: '查询代收 / 代付订单详情' },
  { cmd: '/xe 金额', desc: '设置代付订单二次确认限额，0 表示取消限额' },
  { cmd: '/xq', desc: '查询当前代付订单二次确认限额' },
  { cmd: '/trsq 用户ID|@用户名', desc: '设置可点击交易按钮的人（clear 恢复默认）' },
  { cmd: '/yj 金额', desc: '设置余额不足提醒的预警金额' },
  { cmd: '/ye', desc: '查询当前群绑定商户的余额' },
]

const walletCmds = [
  { cmd: '/yue', desc: '查询钱包余额' },
  { cmd: '/zd', desc: '查询我的账单流水' },
  { cmd: '/cz 金额', desc: '充值（返回支付链接，支付完成后回调入账）' },
  { cmd: '/tx 金额', desc: '提现（提交后冻结余额，由上游代付）' },
]

const ocrFeatures = [
  { name: '识图（OCR 提取文字）', trigger: '群里发图片', desc: '识别图片里的文字并回复到群里。' },
]

const realmanFeatures = [
  { name: '消息私发 / 群发', desc: '用真人号向单个用户或目标群发送消息。' },
  { name: '@发送 / 踢人', desc: '在群里 @某人 或把人移出群。' },
  { name: '快捷回复', desc: '命中关键词时自动回复预设内容。' },
  { name: '表情包采集', desc: '采集群内表情包素材。' },
]

const otherDrivers = [
  { name: '调用三方接口', desc: '按平台接口规范调上游接口（如查询余额、下单等）。' },
  { name: '绑定 / 保存数据', desc: '把用户发来的数据保存下来。' },
  { name: 'Mini App', desc: 'Telegram Mini App 相关能力。' },
]
</script>
