<template>
    <!-- 功能配置对话框 -->
    <el-dialog v-model="visible" :title="dialogTitle" width="1200px" destroy-on-close @close="handleClose">
        <div v-loading="loading">
            <!-- 群维度：说明「只能从机器人分配的功能里挑」 -->
            <el-alert
                v-if="scope === 'group'"
                type="info"
                :closable="false"
                show-icon
                class="mb-3"
                :title="$t('featureConfig.groupScopeTitle')"
                :description="$t('featureConfig.groupScopeTip')"
            />

            <!-- 顶部筛选 -->
            <div class="flex items-center gap-3 mb-3">
                <el-input v-model="keyword" :placeholder="$t('featureConfig.searchFeature')" clearable class="w-64" />
                <el-checkbox v-model="onlyUpstream">{{ $t('featureConfig.onlyUpstream') }}</el-checkbox>
                <span class="text-xs text-gray-400 ml-auto">{{ $t('featureConfig.upstreamHint') }}</span>
            </div>

            <div class="flex gap-4">
                <!-- 左侧：未配置功能 -->
                <div class="flex-1 border rounded p-4 flex flex-col" style="height: 520px;">
                    <div class="text-lg font-semibold mb-3">{{ $t('featureConfig.unconfigured') }}</div>
                    <div class="flex-1 overflow-y-auto space-y-2">
                        <div
                            v-for="feature in filteredAvailable"
                            :key="feature.id"
                            class="p-3 border rounded hover:bg-gray-50 cursor-pointer"
                            @click="handleAddFeature(feature)"
                        >
                            <FeatureRow :feature="feature" :category-text="getCategoryText(feature.category)" />
                        </div>
                        <div v-if="filteredAvailable.length === 0" class="text-center text-gray-400 py-8">
                            {{ noAvailableText }}
                        </div>
                    </div>
                    <el-pagination
                        v-if="availableTotal > 0"
                        class="mt-3"
                        layout="prev, pager, next"
                        :total="availableTotal"
                        :page-size="availablePageSize"
                        v-model:current-page="availablePage"
                        small
                    />
                </div>

                <!-- 右侧：已配置功能 -->
                <div class="flex-1 border rounded p-4 flex flex-col" style="height: 520px;">
                    <div class="text-lg font-semibold mb-3">{{ $t('featureConfig.configured') }}</div>
                    <div class="flex-1 overflow-y-auto space-y-2">
                        <div
                            v-for="feature in selectedFeatures"
                            :key="feature.id"
                            class="p-3 border rounded"
                            :class="{ 'border-red-300 bg-red-50/40': upstreamMissing(feature) }"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <FeatureRow :feature="feature" :category-text="getCategoryText(feature.category)" />
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <el-switch
                                        v-model="feature.enable"
                                        :active-value="1"
                                        :inactive-value="0"
                                        :active-text="$t('system.on')"
                                        :inactive-text="$t('system.off')"
                                    />
                                    <el-button type="danger" size="small" link @click="handleRemoveFeature(feature)">
                                        {{ $t('featureConfig.remove') }}
                                    </el-button>
                                </div>
                            </div>

                            <!-- 上游标记：绑定了哪个上游要一眼看得见 -->
                            <div v-if="feature.needs_upstream" class="mt-2 flex items-center gap-2 flex-wrap">
                                <el-tag v-if="feature.third_config_id" type="success" size="small" effect="dark">
                                    {{ upstreamName(feature.third_config_id) }}
                                </el-tag>
                                <el-tag v-else type="danger" size="small" effect="plain">
                                    {{ $t('featureConfig.upstreamMissing') }}
                                </el-tag>

                                <!-- 机器人维度可指定；群维度只读继承，避免群里挑错上游串账 -->
                                <el-select
                                    v-if="scope === 'bot'"
                                    v-model="feature.third_config_id"
                                    :placeholder="$t('featureConfig.selectUpstream')"
                                    clearable
                                    filterable
                                    size="small"
                                    class="w-64"
                                >
                                    <el-option
                                        v-for="c in allowedConfigsOf(feature)"
                                        :key="c.id"
                                        :label="c.name"
                                        :value="c.id"
                                    />
                                </el-select>
                                <span v-if="scope === 'bot' && allowedConfigsOf(feature).length === 0"
                                      class="text-xs text-orange-500">
                                    {{ $t('featureConfig.noCandidateUpstream') }}
                                </span>
                            </div>
                            <div v-else class="mt-2">
                                <el-tag type="info" size="small" effect="plain">{{ $t('featureConfig.officialApi') }}</el-tag>
                            </div>
                        </div>
                        <div v-if="selectedFeatures.length === 0" class="text-center text-gray-400 py-8">
                            {{ $t('featureConfig.noConfigured') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="dialog-footer">
                <el-button @click="handleClose">{{ $t('system.cancel') }}</el-button>
                <el-button type="primary" @click="handleSave" :loading="saving">
                    {{ $t('featureConfig.saveConfig') }}
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script lang="ts" setup>
import { computed, defineComponent, h, ref, watch } from 'vue'
import { useGroup } from '@/stores/modules/telegram/group'
import Message from '@/support/message'
import http from '@/support/http'
import { t } from '@/support/helper'

/** 一条功能卡片：名称 / 分类 / 上游标记 / 描述 / 接口 code / 命令 */
const FeatureRow = defineComponent({
    props: {
        feature: { type: Object, required: true },
        categoryText: { type: String, default: '' }
    },
    setup(props) {
        return () => {
            const f: any = props.feature
            const tags = [
                h('span', { class: 'text-xs text-gray-500' }, props.categoryText || f.category),
                f.needs_upstream
                    ? h('span', { class: 'text-xs px-1.5 py-0.5 rounded bg-orange-100 text-orange-700' }, t('featureConfig.needsUpstream'))
                    : h('span', { class: 'text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-600' }, t('featureConfig.officialApi'))
            ]

            if (Array.isArray(f.commands) && f.commands.length) {
                tags.push(...f.commands.slice(0, 3).map((c: any) =>
                    h('code', { class: 'text-xs px-1 bg-blue-50 text-blue-700 rounded' }, typeof c === 'string' ? c : c.command)
                ))
            }

            const children: any[] = [
                h('div', { class: 'font-medium' }, f.name),
                h('div', { class: 'flex items-center gap-1.5 flex-wrap mt-1' }, tags)
            ]

            if (f.description) {
                children.push(h('div', {
                    class: 'text-xs text-gray-500 mt-1 truncate',
                    title: f.description
                }, f.description))
            }

            if (Array.isArray(f.upstream_endpoints) && f.upstream_endpoints.length) {
                children.push(h('div', { class: 'text-xs text-gray-400 mt-1 truncate' },
                    `${t('featureConfig.endpoints')}: ${f.upstream_endpoints.join(', ')}`))
            }

            return h('div', { class: 'min-w-0' }, children)
        }
    }
})

interface Props {
    modelValue: boolean
    targetId: number | null
    category?: string
    botId: number | null
    initialFeatures?: any[]
    scope?: 'bot' | 'group'
    dialogTitle?: string
}

const props = withDefaults(defineProps<Props>(), {
    category: 'bot',
    initialFeatures: () => [],
    scope: 'bot',
    dialogTitle: ''
})

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
    (e: 'saved'): void
}>()

const categoryMap: Record<string, string> = {
    'system': t('feature.categories.system'),
    'custom': t('feature.categories.custom'),
    'bot': t('feature.categories.bot'),
    'realMan': t('feature.categories.realMan')
}

const visible = ref(false)
const loading = ref(false)
const saving = ref(false)
const keyword = ref('')
const onlyUpstream = ref(false)

type FeatureItem = {
    id: number
    name: string
    category: string
    description?: string
    needs_upstream: boolean
    upstream_endpoints?: string[]
    commands?: any[]
    allowed_configs?: Array<{ id: number; name: string }>
    enable: number
    third_config_id: number | null
}

const availableFeatures = ref<FeatureItem[]>([])
const selectedFeatures = ref<FeatureItem[]>([])
const featureMeta = ref<Record<number, any>>({})
const upstreamOptions = ref<Array<{ id: number; name: string }>>([])

const availablePage = ref(1)
const availablePageSize = ref(10)
const availableTotal = ref(0)

const dialogTitle = computed(() =>
    props.dialogTitle || (props.scope === 'group' ? t('featureConfig.titleForGroup') : t('featureConfig.title'))
)
const noAvailableText = computed(() =>
    props.scope === 'group' ? t('featureConfig.noAvailableForGroup') : t('featureConfig.noAvailable')
)

const filteredAvailable = computed(() => availableFeatures.value.filter(f => {
    if (onlyUpstream.value && !f.needs_upstream) return false
    if (keyword.value && !`${f.name} ${f.description || ''}`.toLowerCase().includes(keyword.value.toLowerCase())) return false
    return true
}))

watch(() => props.modelValue, (newVal) => {
    visible.value = newVal
    if (newVal && (props.targetId || props.botId)) {
        loadData()
    }
})
watch(visible, (newVal) => emit('update:modelValue', newVal))
watch(availablePage, () => { if (visible.value) loadAvailableFeatures(availablePage.value) })

const getCategoryText = (category: string): string => categoryMap[category] || category

const normalizeUpstreamId = (value: any): number | null => {
    if (value === null || value === undefined || value === '') return null
    const n = Number(value)
    return Number.isFinite(n) && n > 0 ? n : null
}

const withMeta = (f: any): FeatureItem => {
    const meta = featureMeta.value[Number(f.id)] || {}
    return {
        id: Number(f.id),
        name: f.name || meta.name || '',
        category: f.category || meta.category || '',
        description: f.description ?? meta.description ?? '',
        needs_upstream: Boolean(f.needs_upstream ?? meta.needs_upstream ?? false),
        upstream_endpoints: f.upstream_endpoints ?? meta.upstream_endpoints ?? [],
        commands: f.commands ?? meta.commands ?? [],
        allowed_configs: f.allowed_configs ?? meta.allowed_configs ?? [],
        enable: f.enable ?? 1,
        third_config_id: normalizeUpstreamId(f.third_config_id)
    }
}

const upstreamName = (id: number | null): string =>
    upstreamOptions.value.find(c => c.id === id)?.name || `#${id}`

const allowedConfigsOf = (f: FeatureItem): Array<{ id: number; name: string }> =>
    f.allowed_configs || []

const upstreamMissing = (f: FeatureItem): boolean =>
    props.scope === 'bot' && Boolean(f.needs_upstream) && !f.third_config_id

/** 全量功能元信息：右侧「已配置」来自 initialFeatures，本身没有 needs_upstream 等字段 */
const loadFeatureMeta = async () => {
    try {
        const r = await http.get('telegram/features/upstream-meta', {
            bot_id: props.botId || undefined
        })
        const payload = r.data?.data
        const list = Array.isArray(payload?.features) ? payload.features : []
        const map: Record<number, any> = {}
        list.forEach((f: any) => { map[Number(f.id)] = f })
        featureMeta.value = map
    } catch {
        featureMeta.value = {}
    }
}

/** 上游候选只取「机器人已绑定的上游」，不再把全平台上游都列出来 */
const loadUpstreams = async () => {
    if (!props.botId) {
        upstreamOptions.value = []
        return
    }
    try {
        const r = await http.get(`telegram/bots/${props.botId}/upstreams`)
        const payload = r.data?.data
        upstreamOptions.value = Array.isArray(payload?.configs) ? payload.configs : []
    } catch {
        upstreamOptions.value = []
    }
}

const loadAvailableFeatures = async (page: number = 1) => {
    try {
        const excludeIds = selectedFeatures.value.map(f => f.id)
        const response = await useGroup().getMultipleFeatures(
            page,
            availablePageSize.value,
            props.targetId || undefined,
            props.category,
            props.botId || undefined,
            props.scope === 'group',
            excludeIds
        )

        if (response && response.data) {
            let list = Array.isArray(response.data) ? response.data : []
            if (props.category !== 'realMan') {
                list = list.filter((f: any) => f.category !== 'realMan')
            }
            availableFeatures.value = list.map(withMeta)
            availableTotal.value = (response as any)?.total || 0

            if (list.length === 0 && page > 1) {
                availablePage.value = page - 1
                return loadAvailableFeatures(page - 1)
            }
        }
    } catch (error) {
        console.error('获取可用功能列表失败:', error)
    }
}

const loadData = async () => {
    if (!props.targetId && !props.botId) return

    loading.value = true
    availablePage.value = 1
    keyword.value = ''
    onlyUpstream.value = false

    try {
        await Promise.all([loadFeatureMeta(), loadUpstreams()])

        selectedFeatures.value = (props.initialFeatures || []).map((bind: any) => withMeta({
            id: bind.feature_id,
            name: bind.feature?.name || '',
            category: bind.feature?.category || '',
            enable: bind.enabled ? 1 : 0,
            third_config_id: bind.third_config_id
        }))

        await loadAvailableFeatures(1)
    } catch (error) {
        console.error('获取功能列表失败:', error)
    } finally {
        loading.value = false
    }
}

const handleAddFeature = (feature: FeatureItem) => {
    if (selectedFeatures.value.some(f => f.id === feature.id)) return
    selectedFeatures.value.push(withMeta({ ...feature, enable: 1, third_config_id: null }))
    loadAvailableFeatures(availablePage.value)
}

const handleRemoveFeature = (feature: FeatureItem) => {
    selectedFeatures.value = selectedFeatures.value.filter(f => f.id !== feature.id)
    loadAvailableFeatures(availablePage.value)
}

const handleSave = async () => {
    if (!props.targetId && !props.botId) return
    if (props.scope === 'group' && !props.botId) return

    // 机器人维度：配了多上游候选池的功能必须指定上游，否则运行期会报「未配置上游」
    if (props.scope === 'bot' && upstreamOptions.value.length > 0) {
        const missing = selectedFeatures.value.find(upstreamMissing)
        if (missing) {
            Message.error(`${missing.name}: ${t('featureConfig.upstreamMissing')}`)
            return
        }
    }

    saving.value = true
    try {
        const featureIds = selectedFeatures.value.map(f => ({
            feature_id: f.id,
            enable: f.enable,
            // 官方 API / 本地功能一律不带上游；群维度继承机器人绑定
            third_config_id: props.scope === 'bot' && f.needs_upstream ? (f.third_config_id || null) : null
        }))

        const response = await useGroup().setBindFeatures(
            props.scope === 'group' ? props.targetId : null,
            props.botId || null,
            featureIds as any
        )

        if ((response as any)?.code === 10000 || (response as any)?.success) {
            Message.success(response.message || t('featureConfig.saved'))
            emit('saved')
            handleClose()
        }
    } catch (error) {
        console.error('保存功能配置失败:', error)
    } finally {
        saving.value = false
    }
}

const handleClose = () => { visible.value = false }
</script>
