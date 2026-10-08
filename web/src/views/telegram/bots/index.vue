<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="Bot-ID" prop="name">
                    <el-input v-model="query.name" name="name" clearable />
                </el-form-item>
                <el-form-item label="Bot用户名" prop="username">
                    <el-input v-model="query.username" name="username" clearable />
                </el-form-item>
                <el-form-item label="状态" prop="enabled">
                    <el-select v-model="query.enabled" placeholder="请选择" clearable>
                        <el-option v-for="item in enabled" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <!-- <div class="pt-5 pl-2">
                <el-button type="primary" plain @click="openBroadcastDialog">
                    <Icon name="paper-plane" className="w-4 h-4 mr-1" /> 消息群发
                </el-button>
            </div> -->
            <Operate :show="open" />
            <el-table :data="tableData" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="id" />
                <el-table-column prop="api_token" label="Api_Token" />
                <el-table-column prop="username" label="Bot用户名" />
                <el-table-column prop="url_token" label="Url-Token" />
      <el-table-column label="三方上游" min-width="200">
        <template #default="scope">
          <el-select
            v-model="scope.row.third_config_id"
            placeholder="未指定"
            clearable
            filterable
            class="w-full"
            :loading="thirdLoading"
            @change="(v: any) => saveThirdConfig(scope.row, v)"
          >
            <el-option
              v-for="c in thirdConfigs"
              :key="c.id"
              :label="c.name"
              :value="c.id"
            />
          </el-select>
        </template>
      </el-table-column>
                <el-table-column prop="description" label="Bot描述" />
                <el-table-column prop="enabled" label="激活状态">
                    <template #default="scope">
                        <el-switch
                            v-model="scope.row.enabled"
                            :active-value="1"
                            :inactive-value="0"
                            @change="handleEnabledChange(scope.row)"
                            :loading="scope.row.switchLoading"
                        />
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" />
                <el-table-column prop="updated_at" label="更新时间" />
                <el-table-column label="操作" width="460">
                    <template #default="scope">
                        <el-button type="info" size="small" :loading="scope.row.infoLoading" @click="handleViewWebhook(scope.row)">
                            <Icon name="info" className="w-4 h-4 mr-1" /> 查看状态
                        </el-button>
                        <el-button type="success" size="small" @click="openBotBroadcastDialog(scope.row)">
                            <Icon name="paper-plane" className="w-4 h-4 mr-1" /> 群发
                        </el-button>
                        <Update @click="open(scope.row.id)" />
                        <Destroy @click="destroy(api, scope.row.id)" />
                    </template>
                </el-table-column>
            </el-table>
            <Paginate />
        </div>

        <Dialog v-model="visible" :title="title" destroy-on-close>
            <Create @close="close(reset)" :primary="id" :api="api" />
        </Dialog>

        <!-- 消息群发对话框 -->
        <BroadcastDialog
            v-model="broadcastDialog.visible"
            :bot-id="broadcastDialog.botId"
            @success="handleBroadcastSuccess"
        />

        <!-- Webhook 状态查看对话框 -->
        <el-dialog v-model="webhookInfoVisible" title="Webhook 状态" width="640px" destroy-on-close>
            <div v-loading="webhookInfoLoading">
                <el-alert
                    v-if="webhookInfo"
                    :type="webhookStatusType"
                    :title="webhookStatusTitle"
                    :description="webhookStatusDesc"
                    show-icon
                    class="mb-3"
                />
                <el-descriptions v-if="webhookInfo" :column="1" border size="small">
                    <el-descriptions-item label="回调地址（Telegram 实际注册）">
                        {{ webhookInfo.url || '（未设置）' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="最后错误时间">
                        {{ webhookInfo.last_error_date ? formatTs(webhookInfo.last_error_date) : '—' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="最后错误信息">
                        <span :class="webhookInfo.last_error_message ? 'text-red-500' : ''">
                            {{ webhookInfo.last_error_message || '无' }}
                        </span>
                    </el-descriptions-item>
                    <el-descriptions-item label="积压更新数">
                        {{ webhookInfo.pending_update_count ?? '—' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="最大连接数">
                        {{ webhookInfo.max_connections ?? '—' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="允许更新类型">
                        {{ (webhookInfo.allowed_updates && webhookInfo.allowed_updates.length) ? webhookInfo.allowed_updates.join(', ') : '全部' }}
                    </el-descriptions-item>
                    <el-descriptions-item label="自建证书">
                        {{ webhookInfo.has_custom_certificate ? '是' : '否' }}
                    </el-descriptions-item>
                </el-descriptions>
                <div v-else class="text-gray-400 text-sm">暂无数据</div>
            </div>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue'
import Create from './create.vue'
import BroadcastDialog from './BroadcastDialog.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import { useTelegramStore } from '@/stores/modules/telegram'
import { useBotStore } from '@/stores/modules/telegram/botApi'
import Icon from '@/components/icon/index.vue'
import Message from '@/support/message'
import http from '@/support/http'

const api = 'telegram/bots'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()
const telegramStore = useTelegramStore()

// 状态选项定义
const enabled = ref([
    { value: 1, label: '已激活' },
    { value: 0, label: '待激活' }
])

// 处理启用状态变化
const handleEnabledChange = async (row: any) => {
    row.switchLoading = true
    try {
        // 如果是激活（enabled从0变1），调用setBotWebHook
        // 如果是关闭（enabled从1变0），调用delBotWebHook
        if (row.enabled === 1) {
            await telegramStore.setBotWebHook(row.id)
            Message.success('机器人激活成功')
        } else {
            await telegramStore.delBotWebHook(row.id)
            Message.success('机器人已关闭')
        }
        search()
    } catch (error) {
        row.enabled = row.enabled === 1 ? 0 : 1
        Message.error('操作失败')
    } finally {
        row.switchLoading = false
    }
}

const tableData = computed(() => {
    return data.value && 'data' in data.value ? (data.value as any).data : []
})

// 消息群发对话框相关
const broadcastDialog = ref({
    visible: false,
    botId: null as number | null
})

// 打开全局消息群发对话框
const openBroadcastDialog = () => {
    broadcastDialog.value.botId = null
    broadcastDialog.value.visible = true
}

// 打开单个bot的消息群发对话框
const openBotBroadcastDialog = (row: any) => {
    broadcastDialog.value.botId = row.id
    broadcastDialog.value.visible = true
}

// 消息群发成功回调
const handleBroadcastSuccess = () => {
    // 不需要在这里显示提示，子组件已经处理了
}

// Webhook 状态查看
const webhookInfoVisible = ref(false)
const webhookInfoLoading = ref(false)
const webhookInfo = ref<any>(null)

const webhookStatusType = computed<'success' | 'warning' | 'error' | 'info'>(() => {
    if (!webhookInfo.value) return 'info'
    if (!webhookInfo.value.url) return 'warning'
    if (webhookInfo.value.last_error_message) return 'error'
    return 'success'
})
const webhookStatusTitle = computed(() => {
    if (!webhookInfo.value) return ''
    if (!webhookInfo.value.url) return '尚未设置 Webhook'
    if (webhookInfo.value.last_error_message) return 'Webhook 异常'
    return 'Webhook 正常'
})
const webhookStatusDesc = computed(() => {
    if (!webhookInfo.value) return ''
    if (!webhookInfo.value.url) return '该机器人未向 Telegram 注册回调地址，请先在列表开启「激活」。'
    if (webhookInfo.value.last_error_message) return 'Telegram 最近一次投递失败：多为回调地址无法公网访问、非 HTTPS、证书问题或 secret_token 不匹配。'
    return 'Telegram 已成功注册回调地址，机器人可正常接收消息。'
})

const formatTs = (ts: number) => {
    if (!ts) return '—'
    return new Date(ts * 1000).toLocaleString()
}

const handleViewWebhook = async (row: any) => {
    row.infoLoading = true
    webhookInfoLoading.value = true
    webhookInfo.value = null
    try {
        const result = await telegramStore.getWebhookInfo(row.id)
        const payload = result.data
        if (result.success && payload?.status === 'ok') {
            webhookInfo.value = payload.data
            webhookInfoVisible.value = true
        } else {
            Message.error(payload?.message || result.message || '获取状态失败')
        }
    } catch (e) {
        Message.error('获取状态失败')
    } finally {
        row.infoLoading = false
        webhookInfoLoading.value = false
    }
}

// ---- 三方上游（每个机器人绑定自己的上游，实现多用户隔离） ----
const thirdConfigs = ref<any[]>([])
const thirdLoading = ref(false)

const loadThirdConfigs = async () => {
  thirdLoading.value = true
  try {
    const { data } = await http.get('telegram/third/config')
    thirdConfigs.value = data.data?.data || data.data || []
  } catch {
    thirdConfigs.value = []
  } finally {
    thirdLoading.value = false
  }
}

const saveThirdConfig = async (row: any, value: any) => {
  try {
    await http.put(`${api}/${row.id}`, { third_config_id: value ?? null })
    Message.success('已更新该机器人的三方上游')
  } catch {
    Message.error('更新失败')
    search()
  }
}

onMounted(() => {
  search()
  loadThirdConfigs()
  deleted(reset)
})
</script>
