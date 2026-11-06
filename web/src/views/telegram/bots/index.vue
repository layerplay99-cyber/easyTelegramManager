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
      <!-- 三方上游：一个机器人可绑多个上游，默认项用深色 tag 标出 -->
      <el-table-column v-if="isSuperAdmin" :label="$t('bots.thirdUpstream')" min-width="260">
        <template #default="scope">
          <div class="flex items-center gap-1 flex-wrap">
            <el-tag
              v-for="id in (scope.row.upstream_ids || [])"
              :key="id"
              size="small"
              :type="id === scope.row.default_upstream_id ? 'success' : 'info'"
              :effect="id === scope.row.default_upstream_id ? 'dark' : 'plain'"
            >
              {{ thirdConfigName(id) }}
            </el-tag>
            <span v-if="!(scope.row.upstream_ids || []).length" class="text-gray-400 text-xs">
              {{ $t('bots.thirdUnspecified') }}
            </span>
            <el-button link type="primary" size="small" @click="openUpstreamDialog(scope.row)">
              {{ $t('bots.configUpstreams') }}
            </el-button>
          </div>
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

        <!-- 功能配置对话框（机器人维度：给这个机器人分配可用功能） -->
        <FeatureConfig
            v-model="botFeatureDialog.visible"
            scope="bot"
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

        <!-- 一个机器人同时绑定多个上游（同一个上游公司的不同地址 / 项目） -->
        <el-dialog v-model="upstreamDialogVisible" :title="$t('bots.configUpstreams')" width="620px">
            <el-alert type="info" :closable="false" :title="$t('bots.upstreamDialogTip')" class="mb-3" />
            <el-form label-width="110px">
                <el-form-item :label="$t('bots.selectUpstreams')">
                    <el-select v-model="selectedUpstreamIds" multiple filterable collapse-tags collapse-tags-tooltip
                               class="w-full" :loading="thirdLoading" :placeholder="$t('bots.thirdUnspecified')">
                        <el-option v-for="c in thirdConfigs" :key="c.id" :label="c.name" :value="c.id" />
                    </el-select>
                </el-form-item>
                <el-form-item :label="$t('bots.defaultUpstream')">
                    <el-select v-model="defaultUpstreamId" clearable class="w-full"
                               :placeholder="$t('bots.defaultUpstream')">
                        <el-option v-for="id in selectedUpstreamIds" :key="id"
                                   :label="thirdConfigName(id)" :value="id" />
                    </el-select>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="upstreamDialogVisible = false">{{ $t('system.cancel') }}</el-button>
                <el-button type="primary" :loading="upstreamSaving" @click="saveUpstreams">
                    {{ $t('system.save') }}
                </el-button>
            </template>
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
import { useGroup } from '@/stores/modules/telegram/group'
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
    const rows = data.value && 'data' in data.value ? (data.value as any).data : []

    // third_config_id 一律转成数字：有的环境里它被驱动返回成字符串，
    // 而下拉 option 的 value 是数字 id，两者不全等，el-select 找不到选项，
    // 结果就是「刚选的上游还在，一刷新/点搜索就变成空」。
    return rows.map((r: any) => ({
        ...r,
        third_config_id: r.third_config_id === null || r.third_config_id === undefined || r.third_config_id === ''
            ? null
            : Number(r.third_config_id)
    }))
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

const thirdConfigName = (id: number): string =>
  thirdConfigs.value.find((c: any) => c.id === id)?.name || `#${id}`

// ---- 机器人多上游弹框 ----
const upstreamDialogVisible = ref(false)
const upstreamSaving = ref(false)
const currentBotId = ref<number | null>(null)
const selectedUpstreamIds = ref<number[]>([])
const defaultUpstreamId = ref<number | null>(null)

const openUpstreamDialog = async (row: any) => {
  currentBotId.value = row.id
  selectedUpstreamIds.value = [...(row.upstream_ids || [])]
  defaultUpstreamId.value = row.default_upstream_id || null
  upstreamDialogVisible.value = true

  try {
    const { data } = await http.get(`telegram/bots/${row.id}/upstreams`)
    const d = data?.data
    if (Array.isArray(d?.selected_ids)) selectedUpstreamIds.value = d.selected_ids
    if (d?.default_id) defaultUpstreamId.value = d.default_id
  } catch {
    // 用列表里带的字段兜底，弹框仍可用
  }
}

const saveUpstreams = async () => {
  if (!currentBotId.value) return

  upstreamSaving.value = true
  try {
    const { data } = await http.put(`telegram/bots/${currentBotId.value}/upstreams`, {
      third_config_ids: selectedUpstreamIds.value,
      default_id: defaultUpstreamId.value
    })

    if (data?.code === 10000 || data?.status === 'success') {
      Message.success(t('bots.thirdUpdated'))
      upstreamDialogVisible.value = false
      search()
    } else {
      Message.error(data?.message || t('system.updateFailed'))
    }
  } catch (e: any) {
    Message.error(e?.response?.data?.message || t('system.updateFailed'))
  } finally {
    upstreamSaving.value = false
  }
}

onMounted(() => {
  search()
  loadThirdConfigs()
  deleted(reset)
})
</script>
