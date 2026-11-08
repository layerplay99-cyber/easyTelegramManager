<template>
  <div class="p-4 max-w-5xl">
    <el-alert type="info" :closable="false" show-icon class="mb-4">
      下面是后台最常用的几类操作流程，按步骤照着做即可。每个页面的字段说明见「后台功能说明」页。
    </el-alert>

    <el-collapse v-model="activeNames">
      <el-collapse-item name="flow1">
        <template #title>
          <span class="text-base font-semibold">① 接入第一个机器人并让功能跑起来</span>
        </template>
        <el-timeline class="mt-2">
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「机器人列表」添加机器人</div>
            <div class="text-gray-500 text-sm mt-1">填 Api_Token、Bot 用户名、Url_Token；「Webhook 指向」选「当前平台」自动填地址。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">打开「激活」开关</div>
            <div class="text-gray-500 text-sm mt-1">系统自动设置 webhook（关闭则卸载）。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「群列表」同步群</div>
            <div class="text-gray-500 text-sm mt-1">先把机器人拉进目标群，再到群列表同步出群。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">配置功能</div>
            <div class="text-gray-500 text-sm mt-1">「功能列表」建功能；再给机器人「功能配置」分配、给群「功能」挑选（群只能从机器人已分配的功能里选）。</div>
          </el-timeline-item>
        </el-timeline>
      </el-collapse-item>

      <el-collapse-item name="flow2">
        <template #title>
          <span class="text-base font-semibold">② 接入一个三方上游（回调 + 接口）</span>
        </template>
        <el-timeline class="mt-2">
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「三方配置」建上游</div>
            <div class="text-gray-500 text-sm mt-1">填名称、api 地址、token、公钥、密钥（<code>secrept_key</code>，我方调上游的出站凭证）。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">生成入站密钥</div>
            <div class="text-gray-500 text-sm mt-1">点「生成密钥」拿 <code>access_key</code> / <code>secret_key</code>（明文只显示一次）发给上游，用于上游回调我方时验签。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">看接口清单 + 按上游覆盖</div>
            <div class="text-gray-500 text-sm mt-1">「三方接口」是只读清单；「上游接口配置」选该上游，按需改路径 / 方法（留空回退平台默认）。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">功能绑定上游</div>
            <div class="text-gray-500 text-sm mt-1">「功能列表」建功能并选「承接上游」；回调类功能再进「回调设置」配路由表、机器人、群。</div>
          </el-timeline-item>
        </el-timeline>
      </el-collapse-item>

      <el-collapse-item name="flow3">
        <template #title>
          <span class="text-base font-semibold">③ 配置钱包收付款</span>
        </template>
        <el-timeline class="mt-2">
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「支付通道」配通道</div>
            <div class="text-gray-500 text-sm mt-1">类型、币种、费率、单笔 / 每日限额、API、密钥。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「交易限额」配限额</div>
            <div class="text-gray-500 text-sm mt-1">按会员等级 + 币种配单笔 / 日 / 月限额。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「汇率」+「风控中心」</div>
            <div class="text-gray-500 text-sm mt-1">维护汇率，配风控规则。</div>
          </el-timeline-item>
          <el-timeline-item type="success" hollow>
            <div class="font-medium">日常对账</div>
            <div class="text-gray-500 text-sm mt-1">会员群里 <code>/cz</code> <code>/tx</code>，后台在「充值订单」「提现订单」审核，在「账本流水」对账。</div>
          </el-timeline-item>
        </el-timeline>
      </el-collapse-item>

      <el-collapse-item name="flow4">
        <template #title>
          <span class="text-base font-semibold">④ 真人号采集 / 客服</span>
        </template>
        <el-timeline class="mt-2">
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「账号仓库」加账号</div>
            <div class="text-gray-500 text-sm mt-1">填 APP ID、APP Hash、手机号。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「登录」</div>
            <div class="text-gray-500 text-sm mt-1">扫码 / 验证码 / 2FA 完成登录。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「同步群」+「功能」</div>
            <div class="text-gray-500 text-sm mt-1">拉取账号名下群，给账号分配真人功能（群发、私发等）。</div>
          </el-timeline-item>
        </el-timeline>
      </el-collapse-item>

      <el-collapse-item name="flow5">
        <template #title>
          <span class="text-base font-semibold">⑤ 号码采集</span>
        </template>
        <el-timeline class="mt-2">
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「号码仓库」导入号码</div>
            <div class="text-gray-500 text-sm mt-1">下载模板 → 填号码 → 导入（xlsx/xls，异步进度），也可手动新增。</div>
          </el-timeline-item>
          <el-timeline-item type="primary" hollow>
            <div class="font-medium">「扫描记录」看结果</div>
            <div class="text-gray-500 text-sm mt-1">查看各号码的 N 天内活跃、用户名、简介、头像。</div>
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
