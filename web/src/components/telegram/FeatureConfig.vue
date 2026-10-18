<template>
    <!-- 功能配置对话框 -->
    <el-dialog v-model="visible" :title="dialogTitle" width="900px" destroy-on-close @close="handleClose">
        <div v-loading="loading">
            <!-- 群维度：说明「只能从机器人分配的功能里挑」 -->
            <el-alert
                v-if="scope === 'group'"
                type="info"
                :closable="false"
                show-icon
                class="mb-4"
                :title="$t('featureConfig.groupScopeTitle')"
                :description="$t('featureConfig.groupScopeTip')"
            />

            <div class="flex gap-4">
                <!-- 左侧：未配置功能 -->
                <div class="flex-1 border rounded p-4 flex flex-col" style="height: 450px;">
                    <div class="text-lg font-semibold mb-4">{{ $t('featureConfig.unconfigured') }}</div>
                    <div class="flex-1 overflow-y-auto space-y-2">
                        <div
                            v-for="feature in availableFeatures"
                            :key="feature.id"
                            class="flex items-center justify-between p-3 border rounded hover:bg-gray-50 cursor-pointer"
                        >
                            <div>
                                <div class="font-medium">{{ feature.name }}</div>
                                <div class="text-sm text-gray-500">{{ getCategoryText(feature.category) }}</div>
                            </div>
                            <el-button
                                type="primary"
                                size="small"
                                @click="handleAddFeature(feature)"
                            >
                                {{ $t('system.add') }}
                            </el-button>
                        </div>
                        <div v-if="availableFeatures.length === 0" class="text-center text-gray-400 py-8">
                            {{ noAvailableText }}
                        </div>
                    </div>
                    <el-pagination
                        v-if="availableTotal > 0"
                        class="mt-4"
                        layout="prev, pager, next"
                        :total="availableTotal"
                        :page-size="availablePageSize"
                        v-model:current-page="availablePage"
                        small
                    />
                </div>

                <!-- 右侧：已配置功能 -->
                <div class="flex-1 border rounded p-4 flex flex-col" style="height: 450px;">
                    <div class="text-lg font-semibold mb-4">{{ $t('featureConfig.configured') }}</div>
                    <div class="flex-1 overflow-y-auto space-y-2">
                        <div
                            v-for="feature in selectedFeatures"
                            :key="feature.id"
                            class="p-3 border rounded"
                        >
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <div class="font-medium">{{ feature.name }}</div>
                                    <div class="text-sm text-gray-500">{{ getCategoryText(feature.category) }}</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <el-switch
                                        v-model="feature.enable"
                                        :active-value="1"
                                        :inactive-value="0"
                                        :active-text="$t('system.on')"
                                        :inactive-text="$t('system.off')"
                                    />
                                    <el-button
                                        type="danger"
                                        size="small"
                                        link
                                        @click="handleRemoveFeature(feature)"
                                    >
                                        {{ $t('featureConfig.remove') }}
                                    </el-button>
                                </div>
                            </div>

                            <!--
                                机器人维度可以给单个功能单独指定上游。
                                留空 = 用机器人列表那一列的兜底上游；
                                选了 = 这个功能走另一个上游（比如 A 功能走甲、B 功能走乙）。
                                群维度不给：群的上游统一随机器人，再让群自己挑会把串单和账单搞乱。
                            -->
                            <div v-if="scope === 'bot'" class="mt-2">
                                <el-select
                                    v-model="feature.third_config_id"
                                    :placeholder="$t('featureConfig.followBotUpstream')"
                                    clearable
                                    filterable
                                    size="small"
                                    class="w-full"
                                    :loading="upstreamLoading"
                                >
                                    <el-option
                                        v-for="c in upstreamOptions"
                                        :key="c.id"
                                        :label="c.name"
                                        :value="c.id"
                                    />
                                </el-select>
                            </div>
                        </div>
                        <div v-if="selectedFeatures.length === 0" class="text-center text-gray-400 py-8">
                            {{ $t('featureConfig.noConfigured') }}
                        </div>
                    </div>
                    <el-pagination
                        v-if="selectedTotal > 0"
                        class="mt-4"
                        layout="prev, pager, next"
                        :total="selectedTotal"
                        :page-size="selectedPageSize"
                        v-model:current-page="selectedPage"
                        small
                    />
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
import { computed, ref, watch } from 'vue'
import { useGroup } from '@/stores/modules/telegram/group'
import Message from '@/support/message'
import http from '@/support/http'
import { t } from '@/support/helper'

interface Props {
    modelValue: boolean
    targetId: number | null
    category?: string
    botId: number | null
    initialFeatures?: any[]
    /**
     * 作用维度
     *   bot   = 给机器人分配可用功能（白名单），chat_id 记 0
     *   group = 群从机器人白名单里挑自己要用的，chat_id 记群 ID
     */
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

// 类型映射
const categoryMap: Record<string, string> = {
    'system': t('feature.categories.system'),
    'custom': t('feature.categories.custom'),
    'bot': t('feature.categories.bot'),
    'realMan': t('feature.categories.realMan')
}

const visible = ref(false)
const loading = ref(false)
const saving = ref(false)

const availableFeatures = ref<Array<{ id: number; name: string; category: string }>>([])
const selectedFeatures = ref<Array<{
    id: number
    name: string
    category: string
    enable: number
    third_config_id: number | null
}>>([])

// 上游候选：机器人维度可以让单个功能单独走某个上游（留空 = 跟随机器人兜底那个）
const upstreamOptions = ref<Array<{ id: number; name: string }>>([])
const upstreamLoading = ref(false)

/**
 * 上游 id 归一化成 number | null
 *
 * 不能直接 Number(value) 判断：Number(null) === 0，
 * 会话树上没配上游（null）会被算成 id=0，下拉显示成一个 "0"，
 * 保存时又把 0 写进 third_config_id，指向一个不存在的上游。
 */
const normalizeUpstreamId = (value: any): number | null => {
    if (value === null || value === undefined || value === '') return null

    const n = Number(value)

    return Number.isFinite(n) && n > 0 ? n : null
}

const loadUpstreams = async () => {
    // 群维度用不到
    if (props.scope !== 'bot' || upstreamOptions.value.length) return

    try {
        upstreamLoading.value = true
        const { data } = await http.get('telegram/third/config')
        const list = Array.isArray(data?.data?.data) ? data.data.data : data?.data
        upstreamOptions.value = Array.isArray(list) ? list : []
    } catch {
        upstreamOptions.value = []
    } finally {
        upstreamLoading.value = false
    }
}

const availablePage = ref(1)
const availablePageSize = ref(10)
const availableTotal = ref(0)

const selectedPage = ref(1)
const selectedPageSize = ref(10)
const selectedTotal = ref(0)

const dialogTitle = computed(() =>
    props.dialogTitle || (props.scope === 'group' ? t('featureConfig.titleForGroup') : t('featureConfig.title'))
)

const noAvailableText = computed(() =>
    props.scope === 'group'
        ? t('featureConfig.noAvailableForGroup')
        : t('featureConfig.noAvailable')
)

// 监听modelValue变化
watch(() => props.modelValue, (newVal) => {
    visible.value = newVal
    if (newVal && (props.targetId || props.botId)) {
        loadData()
    }
})

watch(visible, (newVal) => {
    emit('update:modelValue', newVal)
})

// 监听可用功能分页变化
watch(availablePage, () => {
    if (visible.value) {
        loadAvailableFeatures(availablePage.value)
    }
})

// 获取类型文本
const getCategoryText = (category: string): string => {
    return categoryMap[category] || category
}

// 加载可选功能列表
//
// 群维度只拉「机器人已分配」的那几个：机器人没分配的功能，群不该看到，
// 也不该被配进去（后端同样按 bot_id 过滤，这里只是让界面不误导人）。
const loadAvailableFeatures = async (page: number = 1) => {
    try {
        // 已经在右边「已配置」里的，左边不再列出来
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
            // 机器人维度不展示「真人」类功能（真人功能只供客服号使用）
            if (props.category !== 'realMan') {
                list = list.filter((f: any) => f.category !== 'realMan')
            }
            availableFeatures.value = list
            availableTotal.value = response.total || 0

            // 排除项会让最后一页整页空掉（比如 11 个功能、每页 10 条、第 2 页被排空），
            // 这时退回上一页，否则用户看到的是空列表以为没数据了。
            if (list.length === 0 && page > 1) {
                availablePage.value = page - 1
                return loadAvailableFeatures(page - 1)
            }
        }
    } catch (error) {
        console.error('获取可用功能列表失败:', error)
    }
}

// 加载数据
const loadData = async () => {
    if (!props.targetId && !props.botId) return

    loading.value = true
    availablePage.value = 1
    selectedPage.value = 1

    try {
        // 已选功能由调用方传入（机器人列表/群列表各自拉好后传进来）
        if (props.initialFeatures && props.initialFeatures.length > 0) {
            // 接口里 enabled 是 bool（模型做了 cast），而开关是 1/0。
            // 直接把 true/false 塞给 :active-value="1" 的开关，它会认为当前值
            // 既不是激活值也不是关闭值，于是随手把状态改成 0 ——
            // 结果就是「打开配置页 → 什么都不动 → 保存」，原本开着的功能全被关掉。
            selectedFeatures.value = props.initialFeatures.map((bind: any) => ({
                id: bind.feature_id,
                name: bind.feature?.name || '',
                category: bind.feature?.category || '',
                enable: bind.enabled ? 1 : 0,
                third_config_id: normalizeUpstreamId(bind.third_config_id)
            }))
            selectedTotal.value = props.initialFeatures.length
        } else {
            selectedFeatures.value = []
            selectedTotal.value = 0
        }

        await Promise.all([loadAvailableFeatures(1), loadUpstreams()])
    } catch (error) {
        console.error('获取功能列表失败:', error)
    } finally {
        loading.value = false
    }
}

// 添加功能（本地操作）
const handleAddFeature = (feature: any) => {
    // 挡一下重复：左边列表是异步刷新的，手快连点两次同一个「新增」
    // 右边会多出一条一样的（v-for 的 key 还撞车），保存时被 keyBy 吃掉了，
    // 但用户看到的就是「明明加了两条，只存进去一条」。
    if (selectedFeatures.value.some(f => f.id === feature.id)) return

    selectedFeatures.value.push({
        ...feature,
        enable: 1,
        third_config_id: null
    })
    selectedTotal.value = selectedFeatures.value.length

    loadAvailableFeatures(availablePage.value)
}

// 移除功能（本地操作）
const handleRemoveFeature = (feature: any) => {
    selectedFeatures.value = selectedFeatures.value.filter(f => f.id !== feature.id)
    selectedTotal.value = selectedFeatures.value.length

    loadAvailableFeatures(availablePage.value)
}

// 保存配置
//
// 整份提交：前端把「这个实体最终该有哪几个功能」一次性传过去，
// 后端按 (bot_id, chat_id) 做整存整取，没出现在列表里的就删掉。
// 上游不再逐条选 —— 统一用机器人自己的上游（机器人列表那一列）。
const handleSave = async () => {
    if (!props.targetId && !props.botId) return

    // 群维度必须带机器人：没有归属就无从判断「这个群能用哪些」
    if (props.scope === 'group' && !props.botId) {
        return
    }

    saving.value = true

    try {
        const featureIds = selectedFeatures.value.map(f => ({
            feature_id: f.id,
            enable: f.enable,
            // 跟着机器人走就传 null，别把群维度的字段带出去
            third_config_id: props.scope === 'bot' ? (f.third_config_id || null) : null
        }))

        const response = await useGroup().setBindFeatures(
            props.scope === 'group' ? props.targetId : null,
            props.botId || null,
            featureIds as any
        )

        if (response.success) {
            // 保存后必须告诉用户一声：以前静默关掉弹窗，
            // 网络一抖看起来跟成功一模一样。
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

// 关闭对话框
const handleClose = () => {
    visible.value = false
}
</script>
