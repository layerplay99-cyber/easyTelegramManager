<template>
  <div class="p-4 w-full">
    <el-alert type="info" :closable="false" show-icon title="第一次使用请从这里开始" class="mb-4">
      跟着下面 8 步操作，完成后机器人就能在群里响应命令、接收上游通知。点开每一步照做，带「验证」的一定要确认结果再往下走。
    </el-alert>

    <!-- 折叠面板横向铺满：默认展开第 1 步，点标题展开/收起其它步骤 -->
    <el-collapse v-model="activeNames" class="w-full">
      <el-collapse-item v-for="(s, i) in sections" :key="s.id" :name="s.id">
        <template #title>
          <div class="flex items-center gap-2">
            <span class="flex items-center justify-center w-6 h-6 rounded-full bg-blue-500 text-white text-xs font-bold shrink-0">{{ i + 1 }}</span>
            <span class="font-semibold text-base">{{ s.title }}</span>
            <span class="text-gray-400 text-sm hidden sm:inline">— {{ s.short }}</span>
          </div>
        </template>

        <div class="text-sm text-gray-500 mb-3">目标：{{ s.goal }}</div>

        <ol class="space-y-3">
          <li v-for="(step, n) in s.steps" :key="n" class="flex gap-3">
            <span class="text-gray-400 font-mono text-sm w-6 shrink-0 pt-0.5">{{ n + 1 }}.</span>
            <div class="flex-1 min-w-0">
              <div class="font-medium text-sm">{{ step.action }}</div>
              <div class="text-sm text-gray-600 mt-0.5 leading-6">{{ step.detail }}</div>
              <div v-if="step.tip" class="text-xs text-orange-500 mt-1">注意：{{ step.tip }}</div>
            </div>
          </li>
        </ol>

        <div v-if="s.check" class="mt-4 pt-3 border-t text-sm">
          <span class="text-green-600 font-medium">✓ 验证：</span>
          <span class="text-gray-700">{{ s.check }}</span>
        </div>
      </el-collapse-item>
    </el-collapse>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'

// 默认展开第 1 步；折叠面板支持多个同时展开
const activeNames = ref(['s1'])

const sections = [
  {
    id: 's1',
    short: '确认商户',
    title: '确认归属商户',
    goal: '让后续建的机器人、三方配置都挂在正确的商户下（平台超管才需要做这一步）。',
    steps: [
      { action: '打开「平台管理 → 商户管理」，看列表里是否已有本次要用的商户', detail: '已有就跳过本步；没有则点左上角「新增」。' },
      { action: '填「商户名称」「商户短码」，点「确定」', detail: '商户短码只能用字母、数字、下划线、短横线，且不能和已有商户重复；「状态」保持启用。' },
      { action: '回到页面顶部，在「归属商户」下拉里选中刚建的商户', detail: '之后新建的机器人、三方配置都会归到这个商户，其他商户看不到。' },
    ],
    check: '顶部「归属商户」显示的商户名和你要用的一致。',
  },
  {
    id: 's2',
    short: '添加机器人',
    title: '添加机器人',
    goal: '把 BotFather 给的机器人登记到平台，并把 webhook 地址配好。',
    steps: [
      { action: '打开「机器人管理 → 机器人列表」，点左上角「新增」', detail: '会弹出一个表单。' },
      { action: '填「Api_Token」', detail: '粘贴 BotFather 给你的 token，形如 123456:ABC-DEF...。' },
      { action: '填「Bot 用户名」「Url-Token」', detail: 'Url-Token 自己定一串随机字符串，用于 webhook 地址校验，不要和其他机器人重复。' },
      { action: '「Webhook 指向」选「当前平台」', detail: '选好后「WebHookUrl」会自动填成 你的域名/api/webhook/pull，不用手改。', tip: '只有要转发到外部系统时才选「自定义外链」并手填地址。' },
      { action: '填「Bot 描述」（选填），点「确定」', detail: '保存后列表会多出一行，此时「激活状态」是关的，属正常。' },
    ],
    check: '列表里能看到这个机器人，Api_Token、Bot 用户名都和填写一致。',
  },
  {
    id: 's3',
    short: '激活机器人',
    title: '激活机器人（这一步不做，机器人收不到任何消息）',
    goal: '把 webhook 注册到 Telegram，让消息能送进平台。',
    steps: [
      { action: '在「机器人列表」找到刚建的那行，打开「激活状态」开关', detail: '开关变亮即触发注册，稍等 1~2 秒。' },
      { action: '点同一行的「查看状态」', detail: '弹窗里会显示 webhook 注册地址、最后错误信息、积压更新数。' },
      { action: '确认「webhook 地址」有值、「最后错误信息」为空、「积压更新数」为 0', detail: '若地址为空或有错误信息，先关闭「激活状态」再重新打开一次；仍不行就检查 Api_Token 是否填错、域名 Telegram 能否访问。', tip: '机器人停用或换域名后，也要重新开关一次激活。' },
    ],
    check: '「查看状态」里 webhook 地址有值且无错误，说明平台已能收到 Telegram 推送。',
  },
  {
    id: 's4',
    short: '同步群',
    title: '让机器人进群并同步出群',
    goal: '把 Telegram 里的群变成后台可配置的「群列表」记录。',
    steps: [
      { action: '在 Telegram 客户端里把这个机器人拉进目标群', detail: '机器人需要有读消息权限；私有群要确保已邀请成功。' },
      { action: '在群里随便发一条消息（例如 /start）', detail: '机器人靠收到消息来感知群，不发消息后台可能暂时看不到这个群。' },
      { action: '打开「机器人管理 → 群列表」，点搜索/刷新，找到刚进的群', detail: '列表里会显示「群名称」「群聊ID」「botId」。', tip: '用真人号采集的群，走「号码采集 → 账号仓库 → 同步群」拉取。' },
    ],
    check: '「群列表」里能看到这个群，且群名称和 Telegram 里一致。',
  },
  {
    id: 's5',
    short: '配置群',
    title: '配置群（语种、商户号、欢迎语）',
    goal: '决定这个群用什么语言回复、归属哪个商户。',
    steps: [
      { action: '在「群列表」找到该群，点「编辑」', detail: '打开配置弹窗，群名称、群聊ID 是只读的。' },
      { action: '「语种配置」选这个群要用的语言', detail: '群里所有自动回复、提示都按这个语种出，默认中文。', tip: '语种只有这一个来源，改这里就生效，不用去别处配。' },
      { action: '「商户ID」填该群对应的商户号', detail: '填了之后这个群的业务数据都归该商户；留空则以后在群里用 /bd 商户号 绑定。' },
      { action: '按需填「进群欢迎语」和「自动回复」，点「确定」', detail: '欢迎语支持 username、full_name 占位；自动回复按「关键词 + 回复内容」成对添加，可加多条。' },
    ],
    check: '重新点开「编辑」，刚才填的语种、商户ID 都还在。',
  },
  {
    id: 's6',
    short: '分配功能',
    title: '给机器人和群分配功能',
    goal: '决定这个群能用哪些命令和功能。',
    steps: [
      { action: '打开「附加功能 → 功能列表」，确认需要的功能已「启用」', detail: '列表右侧有启用开关；没启用的功能不会响应。要新建功能就点「新增」，选好「执行器」和「触发方式」后点「确定」。' },
      { action: '打开「机器人管理 → 机器人列表」，点该行的「功能配置」', detail: '弹窗里勾选这个机器人允许使用的功能，点保存。', tip: '这一步是机器人级白名单，不勾的话群里选不到。' },
      { action: '打开「机器人管理 → 群列表」，点该群的「功能」', detail: '从「机器人已分配的功能」里挑这个群要用的，点保存。', tip: '群只能从机器人已分配的功能里挑，两级是子集关系。' },
    ],
    check: '再点开群的「功能」，能看到已勾选的功能；列表上也会显示已绑定数量。',
  },
  {
    id: 's7',
    short: '验证跑通',
    title: '在群里验证',
    goal: '确认机器人真的能收消息、能回复。',
    steps: [
      { action: '在 Telegram 群里发一条已分配功能对应的命令，例如 /yue', detail: '发完等 1~2 秒看机器人是否回复。' },
      { action: '没回复时按顺序排查', detail: '① 机器人「激活状态」是否打开；② 「查看状态」里有无 webhook 错误；③ 功能列表里该功能是否启用；④ 机器人「功能配置」和群「功能」是否都勾了；⑤ 群「语种配置」是否选对。' },
      { action: '需要商户数据的命令（如 /ye、/cx）先在群里发 /bd 商户号', detail: '把群和商户号绑定，再发查询命令才有数据。' },
    ],
    check: '机器人能在群里正常回复，说明主链路已跑通。',
  },
  {
    id: 's8',
    short: '接入上游（可选）',
    title: '接入三方上游（需要上游回调/查接口时才做）',
    goal: '让平台能调上游接口，并接收上游的回调通知。',
    steps: [
      { action: '打开「附加功能 → 三方配置」，点「新增」', detail: '填「接口名称」「api」「token」「公钥」「密钥」，点「确定」。', tip: '这里的「密钥」是我方调上游用的出站凭证（secrept_key），和下面生成的入站密钥不是一回事。' },
      { action: '在列表点该行的「编辑」，在「绑定功能」里勾选要用这个上游的功能，点「保存」', detail: '只列需要交互上游的功能；绑定后「上游接口配置」才会显示对应接口。' },
      { action: '同一个编辑弹窗里点「生成密钥」，立刻复制并保存 access_key 和 secret_key', detail: '弹窗同时给出 headers、canonical、signature 规范，一并发给上游开发。', tip: '明文只显示这一次，关掉就再也看不到，只能重新生成（会让上游旧密钥失效）。' },
      { action: '点该行「接口配置」，会跳到「上游接口配置」并自动选中该上游', detail: '逐行按上游实际文档改「该上游路径」「方法」，看右侧「实际请求地址」预览是否正确，点「保存」。', tip: '路径留空就回退用平台默认；支持 {参数名} 占位，也可以直接填绝对 URL。' },
      { action: '把回调地址给上游', detail: '到「功能列表」找到「接收三方推送」类的功能，点「回调设置」，复制里面的「回调地址」，在「路由表」里加好「商户ID → 机器人 + 群」的映射并保存。' },
      { action: '让上游按规范带四个请求头回调', detail: 'X-Access-Key / X-Timestamp / X-Nonce / X-Signature；验签通过后按路由表把消息发到对应群。' },
    ],
    check: '让上游发一条测试回调，目标群能收到消息；收不到就看「回调设置」里的路由表是否配了对应商户ID。',
  },
]
</script>
