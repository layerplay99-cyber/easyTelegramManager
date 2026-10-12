<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="Bot-ID" prop="name">
                    <el-input v-model="query.name" name="name" clearable />
                </el-form-item>
                <el-form-item :label="$t('bots.username')" prop="username">
                    <el-input v-model="query.username" name="username" clearable />
                </el-form-item>
                <el-form-item :label="$t('system.status')" prop="enabled">
                    <el-select v-model="query.enabled" :placeholder="$t('system.pleaseSelect')" clearable>
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
                <el-table-column prop="username" :label="$t('bots.username')" />
                <el-table-column prop="url_token" label="Url-Token" />
      <!-- 三方上游：仅超级管理员可见/可改（后端也已硬校验） -->
      <el-table-column v-if="isSuperAdmin" :label="$t('bots.thirdUpstream')" min-width="200">
        <template #default="scope">
          <el-select
            v-model="scope.row.third_config_id"
            :placeholder="$t('bots.thirdUnspecified')"
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
                <el-table-column prop="description" :label="$t('bots.description')" />
                <el-table-column prop="enabled" :label="$t('bots.activeStatus')">
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
                <el-table-column prop="created_at" :label="$t('system.createTime')" />
                <el-table-column prop="updated_at" :label="$t('system.updateTime')" />
                <el-table-column :label="$t('system.operation')" width="460">
                    <template #default="scope">
                        <el-button type="info" size="small" :loading="scope.row.infoLoading" @click="handleViewWebhook(scope.row)">
                            <Icon name="info" className="w-4 h-4 mr-1" /> {{ $t('bots.viewStatus') }}
                        </el-button>
                        <el-button type="success" size="small" @click="openBotBroadcastDialog(scope.row)">
                            <Icon name="paper-plane" className="w-4 h-4 mr-1" /> {{ $t('bots.broadcast') }}
                        </el-button>
                        <el-button type="warning" size="small" @click="openBotFeatureDialog(scope.row)">
                            <Icon name="puzzle-piece" className="w-4 h-4 mr-1" /> {{ $t('bots.featureConfig') }}
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

        <!-- 功能配置对话框（机器人维度：绑定功能并逐功能指定上游） -->
        <FeatureConfig
            v-model="botFeatureDialog.visible"
            :target-id="null"
            :bot-id="botFeatureDialog.botId"
            :initial-features="botFeatureDialog.features"
            category=""
            @saved="handleBotFeatureSaved"
        />

        <!-- Webhook 状态查看对话框 -->
        <el-dialog v-model="webhookInfoVisible" :title="$t('bots.webhookTitle')" width="640px" destroy-on-close>
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
                    <el-descriptions-item :label="$t('bots.webhookUrl')">
                        {{ webhookInfo.url || $t('bots.webhookUnset') }}
                    </el-descriptions-item>
                    <el-descriptions-item :label="$t('bots.lastErrorTime')">
                        {{ webhookInfo.last_error_date ? formatTs(webhookInfo.last_error_date) : '—' }}
                    </el-descriptions-item>
                    <el-descriptions-item :label="$t('bots.lastErrorMessage')">
                        <span :class="webhookInfo.last_error_message ? 'text-red-500' : ''">
                            {{ webhookInfo.last_error_message || $t('system.none') }}
                        </span>
                    </el-descriptions-item>
                    <el-descriptions-item :label="$t('bots.pendingUpdates')">
                        {{ webhookInfo.pending_update_count ?? '—' }}
                    </el-descriptions-item>
                    <el-descriptions-item :label="$t('bots.maxConnections')">
                        {{ webhookInfo.max_connections ?? '—' }}
                    </el-descriptions-item>
                    <el-descriptions-item :label="$t('bots.allowedUpdates')">
                        {{ (webhookInfo.allowed_updates && webhookInfo.allowed_updates.length) ? webhookInfo.allowed_updates.join(', ') : $t('system.all') }}
                    </el-descriptions-item>
                    <el-descriptions-item :label="$t('bots.customCert')">
                        {{ webhookInfo.has_custom_certificate ? $t('system.yes') : $t('system.no') }}
                    </el-descriptions-item>
                </el-descriptions>
                <div v-else class="text-gray-400 text-sm">{{ $t('system.noData') }}</div>
            </div>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue'
import Create from './create.vue'
import BroadcastDialog from './BroadcastDialog.vue'
import FeatureConfig from '@/components/telegram/FeatureConfig.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import { useTelegramStore } from '@/stores/modules/telegram'
import { useBotStore } from '@/stores/modules/telegram/botApi'
import { useUserStore } from '@/stores/modules/user'
import Icon from '@/components/icon/index.vue'
import Message from '@/support/message'
import http from '@/support/http'
import { t } from '@/support/helper'

const api = 'telegram/bots'

const userStore = useUserStore()

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()
const telegramStore = useTelegramStore()

// 状态选项定义
const enabled = ref([
    { value: 1, label: t('bots.activated') },
    { value: 0, label: t('bots.pending') }
])

// 处理启用状态变化
const handleEnabledChange = async (row: any) => {
    row.switchLoading = true
    try {
        // 如果是激活（enabled从0变1），调用setBotWebHook
        // 如果是关闭（enabled从1变0），调用delBotWebHook
        if (row.enabled === 1) {
            await telegramStore.setBotWebHook(row.id)
            Message.success(t('bots.activateSuccess'))
        } else {
            await telegramStore.delBotWebHook(row.id)
            Message.success(t('bots.deactivateSuccess'))
        }
        search()
    } catch (error) {
        row.enabled = row.enabled === 1 ? 0 : 1
        Message.error(t('system.operateFailed'))
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

// ---- 机器人「功能配置」对话框 ----
const botFeatureDialog = ref({
    visible: false,
    botId: null as number | null,
    features: [] as any[]
})

const openBotFeatureDialog = async (row: any) => {
    botFeatureDialog.value.botId = row.id
    botFeatureDialog.value.features = []
    try {
        const res = await useGroup().getBindFeatures(null, row.id)
        const payload = res?.data?.data ?? res?.data ?? []
        botFeatureDialog.value.features = Array.isArray(payload) ? payload : (payload?.data ?? [])
    } catch {
        botFeatureDialog.value.features = []
    }
    botFeatureDialog.value.visible = true
}

const handleBotFeatureSaved = () => {
    // 机器人功能绑定变更，刷新列表（不影响三方上游列）
    search()
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
    if (!webhookInfo.value.url) return t('bots.notSetWebhook')
    if (webhookInfo.value.last_error_message) return t('bots.webhookError')
    return t('bots.webhookOk')
})
const webhookStatusDesc = computed(() => {
    if (!webhookInfo.value) return ''
    if (!webhookInfo.value.url) return t('bots.webhookNotSetDesc')
    if (webhookInfo.value.last_error_message) return t('bots.webhookErrorDesc')
    return t('bots.webhookOkDesc')
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
            Message.error(payload?.message || result.message || t('bots.getStatusFailed'))
        }
    } catch (e) {
        Message.error(t('bots.getStatusFailed'))
    } finally {
        row.infoLoading = false
        webhookInfoLoading.value = false
    }
}

// ---- 三方上游（每个机器人绑定自己的上游，实现多用户隔离）----
// 仅超级管理员可指定：上游涉及地址与 Token，属成本与安全边界
const isSuperAdmin = userStore.isSuperAdmin()

const thirdConfigs = ref<any[]>([])
const thirdLoading = ref(false)

const loadThirdConfigs = async () => {
  // 非超管不加载，也不暴露上游信息
  if (!isSuperAdmin) return
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
    Message.success(t('bots.thirdUpdated'))
  } catch {
    Message.error(t('system.updateFailed'))
    search()
  }
}

onMounted(() => {
  search()
  loadThirdConfigs()
  deleted(reset)
})
</script>
