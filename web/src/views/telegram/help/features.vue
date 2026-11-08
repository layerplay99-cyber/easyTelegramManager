<template>
  <div class="p-4 max-w-5xl">
    <el-alert type="info" :closable="false" show-icon class="mb-4">
      按后台侧边栏菜单组织，逐个说明每个页面的用途、字段和操作。带 <code>/</code> 的群命令速查见本页末尾。
    </el-alert>

    <el-collapse v-model="activeNames">
      <el-collapse-item v-for="m in modules" :key="m.title" :name="m.title">
        <template #title>
          <span class="text-base font-semibold">{{ m.title }}</span>
        </template>

        <div v-for="p in m.pages" :key="p.name" class="border-b border-gray-100 py-3 last:border-0">
          <div class="flex items-baseline gap-2">
            <span class="font-semibold">{{ p.name }}</span>
            <span class="text-gray-500 text-sm">{{ p.purpose }}</span>
          </div>
          <div v-if="p.fields.length" class="text-sm mt-1">
            <span class="text-gray-400">字段：</span>
            <span class="text-gray-600">{{ p.fields.join('、') }}</span>
          </div>
          <div class="text-sm mt-1">
            <span class="text-gray-400">操作：</span>
            <span class="text-gray-600">{{ p.ops }}</span>
          </div>
          <div v-if="p.note" class="text-sm mt-1 text-orange-500">{{ p.note }}</div>
        </div>
      </el-collapse-item>
    </el-collapse>

    <el-card shadow="never" class="mt-4">
      <template #header>
        <span class="font-semibold">群内命令速查（补充）</span>
      </template>
      <el-table :data="commands" size="default" border>
        <el-table-column label="命令" width="180">
          <template #default="{ row }"><code>{{ row.cmd }}</code></template>
        </el-table-column>
        <el-table-column prop="desc" label="用途" />
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'

const activeNames = ref<string[]>([])

const modules = [
  {
    title: '一、号码采集',
    pages: [
      { name: '号码仓库', purpose: '存放采集到的手机号。', fields: ['手机号'], ops: '新增 / 编辑 / 删除、导入（异步进度）、导出、下载模板' },
      { name: '扫描记录', purpose: '记录对某号码的扫描结果。', fields: ['被扫电话', 'N天内活跃', '用户名', '简介', '用户头像'], ops: '新增 / 编辑 / 删除' },
      { name: '账号仓库', purpose: '维护用于真人采集 / 客服的 Telegram 账号。', fields: ['APP ID', 'APP Hash', '电话号码', '昵称', '状态'], ops: '登录 / 注销、功能、同步群、导入 / 导出', note: '与「机器人管理 → Telegram 客服」是同一页面；同步的群会进「群列表」。' },
    ],
  },
  {
    title: '二、附加功能',
    pages: [
      { name: '功能列表', purpose: '管理机器人 / 真人可执行的功能。', fields: ['功能名称', '执行器', '触发方式', '功能分类', '命令配置'], ops: '启用开关、回调设置（仅「接收三方推送」类型）' },
      { name: '三方配置', purpose: '维护「我方 → 上游」的接入配置与入站密钥对。', fields: ['接口名称', 'api', 'token', '公钥', '密钥（secrept_key）'], ops: '生成密钥（明文只显示一次）、吊销密钥、接口配置、绑定功能' },
      { name: '三方接口', purpose: '平台接口只读清单 + 默认值管理。', fields: ['接口标识', '名称', '方法', '路径模板', '超时', '请求头'], ops: '仅编辑（接口由代码同步，不能增删）' },
      { name: '上游接口配置', purpose: '为「每个上游 + 平台接口」单独配置路径 / 方法。', fields: ['路径（支持 {参数} 占位）', '方法', '启用', '备注'], ops: '顶部选上游 → 逐行覆盖 → 保存（整批替换）' },
    ],
  },
  {
    title: '三、机器人管理',
    pages: [
      { name: '机器人列表', purpose: '维护 Bot，激活 webhook、群发、配功能与上游。', fields: ['Api_Token', 'Bot 用户名', 'Url_Token', 'Webhook 指向', '默认上游'], ops: '激活 / 关闭、查看状态、群发、功能配置、配置上游' },
      { name: '群分组', purpose: '给群分类，方便批量群发 / 管理。', fields: ['分组名称', '分组描述'], ops: '新增 / 编辑 / 删除' },
      { name: '群列表', purpose: '管理同步上来的群。', fields: ['语种', '商户ID', '进群欢迎语', '自动回复'], ops: '编辑、功能、删除、批量设置分组' },
    ],
  },
  {
    title: '四、钱包管理',
    pages: [
      { name: '会员', purpose: '管理钱包会员，查看钱包、冻结 / 解冻。', fields: ['TG用户ID', '用户名', '状态', '支付密码'], ops: '查看钱包、冻结 / 解冻、重置支付密码' },
      { name: '钱包', purpose: '每个会员分币种的钱包账户，支持人工调账。', fields: ['会员ID', '币种', '可用余额', '冻结余额'], ops: '调整余额、启用 / 禁用、开户' },
      { name: '充值订单', purpose: '查看和管理充值订单，手工补单 / 取消。', fields: ['订单号', '会员ID', '币种', '金额', '状态'], ops: '完成、取消' },
      { name: '提现订单', purpose: '查看和管理提现订单。', fields: ['订单号', '会员ID', '币种', '金额', '实付', '状态'], ops: '处理中、完成、取消、批量处理' },
      { name: '账本流水', purpose: '只读查看所有资金变动流水。', fields: ['会员', '类型', '币种', '变动', '关联单号'], ops: '无（只读）' },
      { name: '支付通道', purpose: '配置充值 / 提现通道及其费率、限额、密钥。', fields: ['通道名称', '类型', '币种', '费率', '单笔/每日限额', 'API', '密钥'], ops: '启用 / 禁用 / 维护切换' },
      { name: '风控中心', purpose: '配置风控规则并处理风控日志。', fields: ['规则名称', '场景', '动作', '风险等级', '触发条件'], ops: '规则启用 / 禁用、日志标记处理' },
      { name: '交易限额', purpose: '按等级 / 币种配充值、提现的单笔、日、月限额。', fields: ['类型', '等级', '币种', '单笔/日/月限额', '日/月笔数'], ops: '启用 / 禁用' },
      { name: '汇率', purpose: '维护货币对汇率。', fields: ['源币种', '目标币种', '基准汇率', '买入价', '卖出价', '来源'], ops: '增删改' },
    ],
  },
  {
    title: '五、平台管理',
    pages: [
      { name: '商户管理', purpose: '维护平台商户（租户），数据隔离单位，仅平台超管可访问。', fields: ['商户名称', '商户短码', '联系人', '状态'], ops: '新增 / 编辑 / 删除（名下有数据则拒绝删除）' },
    ],
  },
]

const commands = [
  { cmd: '/bd 商户号', desc: '绑定 / 查看本群商户号' },
  { cmd: '/ye', desc: '查询本群商户余额' },
  { cmd: '/cx 订单ID', desc: '查询订单详情' },
  { cmd: '/xe 金额', desc: '设置代付二次确认限额' },
  { cmd: '/xq', desc: '查询代付二次确认限额' },
  { cmd: '/trsq 用户', desc: '设置交易操作人' },
  { cmd: '/yj 金额', desc: '设置余额预警' },
  { cmd: '/yue', desc: '钱包余额' },
  { cmd: '/zd', desc: '钱包账单' },
  { cmd: '/cz 金额', desc: '钱包充值' },
  { cmd: '/tx 金额', desc: '钱包提现' },
]
</script>
