<template>
    <!-- 功能配置对话框 -->
    <el-dialog v-model="visible" title="功能配置" width="900px" destroy-on-close @close="handleClose">
        <div v-loading="loading">
            <div class="flex gap-4">
                <!-- 左侧：未配置功能 -->
                <div class="flex-1 border rounded p-4 flex flex-col" style="height: 450px;">
                    <div class="text-lg font-semibold mb-4">未配置功能</div>
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
                                添加
                            </el-button>
                        </div>
                        <div v-if="availableFeatures.length === 0" class="text-center text-gray-400 py-8">
                            暂无可用功能
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
                    <div class="text-lg font-semibold mb-4">已配置功能</div>
                    <div class="flex-1 overflow-y-auto space-y-2">
                        <div
                            v-for="feature in selectedFeatures"
                            :key="feature.id"
                            class="flex items-center justify-between p-3 border rounded hover:bg-gray-50"
                        >
                            <div class="flex-1">
                                <div class="font-medium">{{ feature.name }}</div>
                                <div class="text-sm text-gray-500">{{ getCategoryText(feature.category) }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <el-select
                                    v-model="feature.third_config_id"
                                    placeholder="默认上游"
                                    clearable
                                    filterable
                                    class="w-40"
                                    size="small"
                                >
                                    <el-option
                                        v-for="c in thirdConfigs"
                                        :key="c.id"
                                        :label="c.name"
                                        :value="c.id"
                                    />
                                </el-select>
                                <el-switch
                                    v-model="feature.enable"
                                    :active-value="1"
                                    :inactive-value="0"
                                    active-text="开启"
                                    inactive-text="关闭"
                                />
                                <el-button
                                    type="danger"
                                    size="small"
                                    link
                                    @click="handleRemoveFeature(feature)"
                                >
                                    移除
                                </el-button>
                            </div>
                        </div>
                        <div v-if="selectedFeatures.length === 0" class="text-center text-gray-400 py-8">
                            暂无已配置功能
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
                <el-button @click="handleClose">取消</el-button>
                <el-button type="primary" @click="handleSave" :loading="saving">
                    保存配置
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script lang="ts" setup>
import { ref, watch } from 'vue'
import { useGroup } from '@/stores/modules/telegram/group'
import http from '@/support/http'

interface Props {
    modelValue: boolean
    targetId: number | null
    category?: string
    botId: number | null
    initialFeatures?: any[]
}

const props = withDefaults(defineProps<Props>(), {
    category: 'bot',
    initialFeatures: () => []
})

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
    (e: 'saved'): void
}>()

// 类型映射
const categoryMap: Record<string, string> = {
    'system': '系统',
    'custom': '客户端',
    'bot': '机器人',
    'realMan': '真人'
}

const visible = ref(false)
const loading = ref(false)
const saving = ref(false)

const availableFeatures = ref<Array<{ id: number; name: string; category: string }>>([])
const selectedFeatures = ref<Array<{ id: number; name: string; category: string; enable: number; third_config_id?: number | null; bindId?: number }>>([])

// 三方上游配置列表（供每条绑定功能单独选择上游）
const thirdConfigs = ref<Array<{ id: number; name: string }>>([])

const loadThirdConfigs = async () => {
    try {
        const { data } = await http.get('telegram/third/config')
        thirdConfigs.value = data?.data?.data || data?.data || []
    } catch {
        thirdConfigs.value = []
    }
}

const availablePage = ref(1)
const availablePageSize = ref(10)
const availableTotal = ref(0)

const selectedPage = ref(1)
const selectedPageSize = ref(10)
const selectedTotal = ref(0)

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

// 监听已选功能分页变化（使用 initialFeatures，不需要调用 API）
watch(selectedPage, async (newPage) => {
    // 已选功能使用 initialFeatures，不需要分页加载
})

// 获取类型文本
const getCategoryText = (category: string): string => {
    return categoryMap[category] || category
}

// 加载可用功能列表（使用后端分页）
const loadAvailableFeatures = async (page: number = 1) => {
    try {
        const response = await useGroup().getMultipleFeatures(
            page,
            availablePageSize.value,
            props.targetId || undefined,
            props.category,
            props.botId || undefined
        )

        if (response && response.data) {
            let list = Array.isArray(response.data) ? response.data : []
            // 机器人维度不展示「真人」类功能（真人功能只供客服号使用）
            if (props.category !== 'realMan') {
                list = list.filter((f: any) => f.category !== 'realMan')
            }
            availableFeatures.value = list
            availableTotal.value = response.total || 0
        }
    } catch (error) {
        console.error('获取可用功能列表失败:', error)
    }
}// 加载数据
const loadData = async () => {
    if (!props.targetId && !props.botId) return

    loading.value = true
    availablePage.value = 1
    selectedPage.value = 1

    try {
        // 加载三方上游配置（每条绑定功能可单独选上游）
        await loadThirdConfigs()

        // 使用 initialFeatures 初始化已选功能
        if (props.initialFeatures && props.initialFeatures.length > 0) {
            selectedFeatures.value = props.initialFeatures.map((bind: any) => ({
                id: bind.feature_id,
                name: bind.feature?.name || '',
                category: bind.feature?.category || '',
                enable: bind.enabled,
                third_config_id: bind.third_config_id ?? null,
                bindId: bind.id
            }))
            selectedTotal.value = props.initialFeatures.length
        } else {
            selectedFeatures.value = []
            selectedTotal.value = 0
        }

        // 加载可用功能
        await loadAvailableFeatures(1)
    } catch (error) {
        console.error('获取功能列表失败:', error)
    } finally {
        loading.value = false
    }
}

// 添加功能（本地操作）
const handleAddFeature = (feature: any) => {
    // 添加到已选列表，默认开启
    const newFeature = {
        ...feature,
        enable: 1,
        third_config_id: null
    }
    selectedFeatures.value.push(newFeature)
    selectedTotal.value = selectedFeatures.value.length

    // 重新加载当前页
    loadAvailableFeatures(availablePage.value)
}

// 移除功能（本地操作）
const handleRemoveFeature = (feature: any) => {
    // 从已选列表移除
    selectedFeatures.value = selectedFeatures.value.filter(f => f.id !== feature.id)
    selectedTotal.value = selectedFeatures.value.length

    // 重新加载当前页
    loadAvailableFeatures(availablePage.value)
}

// 保存配置
const handleSave = async () => {
    if (!props.targetId && !props.botId) return

    saving.value = true

    try {
        const featureIds = selectedFeatures.value.map(f => ({
            feature_id: f.id,
            enable: f.enable,
            third_config_id: f.third_config_id ?? null
        }))

        const response = await useGroup().setBindFeatures(
            props.targetId || null,
            props.botId || null,
            featureIds as any
        )

        if (response.success) {
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
