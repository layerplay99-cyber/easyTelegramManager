<template>
  <div class="p-4 max-w-5xl">
    <el-alert type="info" :closable="false" show-icon class="mb-4">
      下面是最常用的几类操作流程，按步骤照着做即可。每个入口的字段说明见「功能说明」页。
    </el-alert>

    <el-collapse v-model="activeNames">
      <!-- 流程 1 -->
      <el-collapse-item name="flow1">
        <template #title>
          <span class="text-base font-semibold">① 接入三方上游回调：让交易通知自动发到群里</span>
        </template>

        <el-timeline class="mt-2">
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">在「三方配置」新建上游并生成密钥</div>
            <div class="text-gray-500 text-sm mt-1">
              点「生成密钥」拿到 <code>access_key</code> / <code>secret_key</code>，发给上游。
              <code>secret_key</code> 只显示一次，请当场保存。
            </div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">在「功能列表」新建功能</div>
            <div class="text-gray-500 text-sm mt-1">
              执行器选「接收三方推送」（通用）或「[示例]交易通知」。
            </div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">配置「回调设置」</div>
            <div class="text-gray-500 text-sm mt-1">
              填默认机器人、路由字段（如 <code>merchant_id</code>）、兜底群，保存后拿到回调地址 <code>/api/hooks/{token}</code>。
            </div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">在群里绑定商户号</div>
            <div class="text-gray-500 text-sm mt-1">
              群里发 <code>/bd 商户号</code>，把群和商户号绑到一起。
            </div>
          </el-timeline-item>
          <el-timeline-item type="success" hollow>
            <div class="font-medium">上游带签名头回调，验签通过后群里收到通知</div>
            <div class="text-gray-500 text-sm mt-1">
              回调需带 <code>X-Access-Key</code> / <code>X-Timestamp</code> / <code>X-Nonce</code> / <code>X-Signature</code> 四个请求头。
            </div>
          </el-timeline-item>
        </el-timeline>
      </el-collapse-item>

      <!-- 流程 2 -->
      <el-collapse-item name="flow2">
        <template #title>
          <span class="text-base font-semibold">② 配置群内「确认 / 驳回」按钮</span>
        </template>

        <el-timeline class="mt-2">
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">回调功能选「交易通知 → 群内按钮」</div>
            <div class="text-gray-500 text-sm mt-1">
              在「回调设置 → 消息处理」里按需转换上游字段，让通知变成带按钮的消息。
            </div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">新建「交互按钮点击处理」功能</div>
            <div class="text-gray-500 text-sm mt-1">
              绑定同一机器人 + 同一群，在 branches 里配「确认 / 驳回」各自的行为（调用三方接口、回复内容等）。
            </div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">群里指定谁能点按钮</div>
            <div class="text-gray-500 text-sm mt-1">
              群里发 <code>/trsq 用户ID 或 @用户名</code>，只允许这些人操作交易按钮。
            </div>
          </el-timeline-item>
          <el-timeline-item type="success" hollow>
            <div class="font-medium">上游回调 → 群里出现按钮 → 指定人点击 → 结果提交上游</div>
          </el-timeline-item>
        </el-timeline>
      </el-collapse-item>

      <!-- 流程 3 -->
      <el-collapse-item name="flow3">
        <template #title>
          <span class="text-base font-semibold">③ 钱包充值 / 提现</span>
        </template>

        <el-timeline class="mt-2">
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">群里发 <code>/cz 金额</code> 充值</div>
            <div class="text-gray-500 text-sm mt-1">机器人返回支付链接，完成支付后回调入账。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">查余额 / 账单</div>
            <div class="text-gray-500 text-sm mt-1"><code>/yue</code> 查余额，<code>/zd</code> 查账单流水。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">群里发 <code>/tx 金额</code> 提现</div>
            <div class="text-gray-500 text-sm mt-1">提交后冻结余额，由上游代付，到账后扣减。</div>
          </el-timeline-item>
          <el-timeline-item type="info" hollow>
            <div class="font-medium">涉及资金的功能在后台「钱包管理」里统一管控</div>
            <div class="text-gray-500 text-sm mt-1">支付通道、交易限额、风控、汇率等都在「钱包管理」目录下配置。</div>
          </el-timeline-item>
        </el-timeline>
      </el-collapse-item>

      <!-- 流程 4 -->
      <el-collapse-item name="flow4">
        <template #title>
          <span class="text-base font-semibold">④ 机器人 + 群 + 功能绑定（基础配置）</span>
        </template>

        <el-timeline class="mt-2">
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「机器人列表」添加机器人</div>
            <div class="text-gray-500 text-sm mt-1">填机器人的 api_token（BotFather 处获取）。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「群列表」同步群</div>
            <div class="text-gray-500 text-sm mt-1">把机器人拉进群后，在「群列表」同步出群。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「功能列表」把功能绑定到机器人 + 群</div>
            <div class="text-gray-500 text-sm mt-1">一个功能可以绑定到多个群，群只能使用机器人已分配的功能。</div>
          </el-timeline-item>
        </el-timeline>
      </el-collapse-item>

      <!-- 流程 5 -->
      <el-collapse-item name="flow5">
        <template #title>
          <span class="text-base font-semibold">⑤ 真人号群发 / 私发</span>
        </template>

        <el-timeline class="mt-2">
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">准备真人号账号</div>
            <div class="text-gray-500 text-sm mt-1">在「机器人列表」里添加「真人账号操作」类型的账号。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">新建功能并选对应执行器</div>
            <div class="text-gray-500 text-sm mt-1">如「消息群发」「消息私发」「@发送」「踢人」「快捷回复」等，绑定目标群。</div>
          </el-timeline-item>
          <el-timeline-item type="success" hollow>
            <div class="font-medium">触发后按配置向目标群 / 用户发送</div>
          </el-timeline-item>
        </el-timeline>
      </el-collapse-item>
    </el-collapse>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'

const activeNames = ref(['flow1'])
</script>
