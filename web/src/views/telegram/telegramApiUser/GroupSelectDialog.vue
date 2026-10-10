<template>
    <el-dialog v-model="visible" :title="dialogTitle" width="900px" @close="handleClose">
        <div v-loading="loading">
            <!-- 消息内容编辑区域 -->
            <div v-if="needMessageInput" class="mb-4">
                <el-form :model="formData" label-width="100px">
                    <el-form-item :label="$t('groupSelect.messageType')">
                        <el-select v-model="formData.type" :placeholder="$t('groupSelect.messageTypePlaceholder')" style="width: 200px">
                            <el-option :label="$t('groupSelect.textType')" value="text" />
                            <el-option :label="$t('groupSelect.mediaType')" value="media" />
                        </el-select>
                    </el-form-item>
                    <el-form-item :label="$t('broadcast.messageContent')">
                        <el-input
                            v-model="formData.text"
                            type="textarea"
                            :rows="6"
                            :placeholder="$t('broadcast.messagePlaceholder')"
                            maxlength="4096"
                            show-word-limit
                        />
                    </el-form-item>
                </el-form>
            </div>

            <div class="mb-4 flex items-center justify-between">
                <el-checkbox v-model="selectAll" @change="handleSelectAllChange">
                    {{ $t('groupSelect.selectCurrentPage') }}
                </el-checkbox>
                <div class="text-sm text-gray-500">
                    {{ $t('broadcast.selected') }} {{ selectedChatIds.length }} {{ $t('broadcast.groups') }}
                </div>
            </div>

            <el-table
                :data="groups"
                style="width: 100%"
                @selection-change="handleSelectionChange"
                ref="tableRef"
            >
                <el-table-column type="selection" width="55" />
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column prop="name" :label="$t('broadcast.groupName')" min-width="200" />
                <el-table-column prop="chat_id" label="Chat ID" width="150" />
            </el-table>

            <el-pagination
                v-if="total > 0"
                class="mt-4"
                layout="total, prev, pager, next, sizes"
                :total="total"
                :page-sizes="[10, 20, 50, 100]"
                v-model:current-page="currentPage"
                v-model:page-size="pageSize"
                @current-change="loadGroups"
                @size-change="handlePageSizeChange"
            />

            <div v-if="groups.length === 0 && !loading" class="text-center text-gray-400 py-8">
                {{ $t('broadcast.noGroups') }}
            </div>
        </div>
        <template #footer>
            <div class="dialog-footer">
                <el-button @click="handleClose">{{ $t('system.cancel') }}</el-button>
                <el-button
                    type="primary"
                    @click="handleSubmit"
                    :loading="submitting"
                    :disabled="selectedChatIds.length === 0"
                >
                    {{ $t('groupSelect.confirmExecute') }} ({{ selectedChatIds.length }})
                </el-button>
            </div>
        </template>
    </el-dialog>
</template><script lang="ts" setup>
import { ref, watch, nextTick, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { useTelegramStore } from '@/stores/modules/telegram/teleApi'
import { useGroup } from '@/stores/modules/telegram/group'
import { useI18n } from 'vue-i18n'

interface Props {
    modelValue: boolean
    row: any
    bind: any
}

const props = defineProps<Props>()
const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
    (e: 'success'): void
}>()

const { t } = useI18n()
const telegramStore = useTelegramStore()
const groupStore = useGroup()

const visible = ref(false)
const loading = ref(false)
const submitting = ref(false)
const groups = ref<any[]>([])
const selectedChatIds = ref<string[]>([])
const selectAll = ref(false)
const tableRef = ref()

// 表单数据
const formData = ref({
    text: '',
    type: 'text' // 默认选择文字类型
})

// 分页相关
const currentPage = ref(1)
const pageSize = ref(20)
const total = ref(0)

// 计算对话框标题
const dialogTitle = computed(() => {
    return props.bind?.feature?.name || t('groupSelect.title')
})

// 判断是否需要消息输入框
const needMessageInput = computed(() => {
    // 按功能标识判断：发送类（群发 / 私发 / @ / 快捷回复）需要输入内容，踢人、表情采集不需要
    const key = props.bind?.feature?.feature || props.bind?.feature?.handler || ''
    return key.includes('send') || key.includes('reply')
})

// 监听 modelValue 变化
watch(() => props.modelValue, (newVal) => {
    visible.value = newVal
    if (newVal) {
        currentPage.value = 1
        selectedChatIds.value = []
        selectAll.value = false
        allGroupsCache.value = [] // 清除缓存，重新加载
        formData.value.text = '' // 清空消息内容
        formData.value.type = 'text' // 重置为文字类型
        loadGroups()
    }
})

watch(visible, (newVal) => {
    emit('update:modelValue', newVal)
})

// 存储所有群组数据（用于前端分页）
const allGroupsCache = ref<any[]>([])

// 加载群组列表
const loadGroups = async () => {
    if (!props.row || !props.row.app_id) return

    loading.value = true

    try {
        // 如果缓存为空，先加载所有数据
        if (allGroupsCache.value.length === 0) {
            const response = await groupStore.selectGroup(0, props.row.app_id)

            if (response.data) {
                // 处理返回的数据结构
                const responseData = response.data.data || response.data || []
                allGroupsCache.value = Array.isArray(responseData) ? responseData : []
                total.value = allGroupsCache.value.length

                if (allGroupsCache.value.length === 0) {
                    ElMessage({
                        message: t('broadcast.syncFirst'),
                        type: 'warning'
                    })
                }
            } else {
                ElMessage({
                    message: response.message || t('broadcast.loadGroupsFailed'),
                    type: 'error'
                })
                allGroupsCache.value = []
                total.value = 0
            }
        }

        // 前端分页
        const start = (currentPage.value - 1) * pageSize.value
        const end = start + pageSize.value
        groups.value = allGroupsCache.value.slice(start, end)

        // 更新表格选中状态
        await nextTick()
        if (tableRef.value && groups.value.length > 0) {
            groups.value.forEach(row => {
                if (selectedChatIds.value.includes(row.chat_id)) {
                    tableRef.value.toggleRowSelection(row, true)
                }
            })
        }

    } catch (error) {
        console.error('加载群组失败:', error)
        ElMessage({
            message: '加载群组失败，请稍后重试',
            type: 'error'
        })
        groups.value = []
        total.value = 0
    } finally {
        loading.value = false
    }
}

// 处理表格选择变化
const handleSelectionChange = (selection: any[]) => {
    selectedChatIds.value = selection.map(item => item.chat_id)
    selectAll.value = selection.length === groups.value.length && groups.value.length > 0
}

// 处理全选变化
const handleSelectAllChange = async (val: boolean) => {
    if (val) {
        // 全选所有群组（不只是当前页）
        selectedChatIds.value = allGroupsCache.value.map(g => g.chat_id)

        // 更新当前页的表格选中状态
        if (tableRef.value) {
            await nextTick()
            groups.value.forEach(row => {
                tableRef.value.toggleRowSelection(row, true)
            })
        }
    } else {
        // 取消全选
        selectedChatIds.value = []
        if (tableRef.value) {
            tableRef.value.clearSelection()
        }
    }
}

// 处理页面大小变化
const handlePageSizeChange = () => {
    currentPage.value = 1
    loadGroups()
}

// 提交选择
const handleSubmit = async () => {
    if (selectedChatIds.value.length === 0) {
        ElMessage({
            message: t('broadcast.selectGroupRequired'),
            type: 'warning'
        })
        return
    }

    // 如果需要消息输入，验证消息内容
    if (needMessageInput.value && !formData.value.text.trim()) {
        ElMessage({
            message: t('broadcast.messageRequired'),
            type: 'warning'
        })
        return
    }

    submitting.value = true
    try {
        // 使用已选择的 chat_id 列表
        const result = await telegramStore.operateFeature(formData.value.type, {
            app_id: props.row.app_id,
            chatIds: selectedChatIds.value,
            text: formData.value.text || '',
            mediaPath: '',
            buttons: [],
            // 统一用功能标识（features.feature，如 realman.sendToGroups）调用
            feature: props.bind.feature.feature
        })

        if (result.success) {
            // 如果 data.code 是 10005，不显示成功消息，但也不关闭弹框
            if (result.data?.code === 10005) {
                ElMessage({
                    message: result.data?.message || result.message || t('groupSelect.partialFailed'),
                    type: 'error'
                })
            } else {
                ElMessage({
                    message: result.message || t('groupSelect.operateSuccess'),
                    type: 'success'
                })
            }
            emit('success')
        } else {
            ElMessage({
                message: result.message || t('groupSelect.operateFailed'),
                type: 'error'
            })
        }
    } catch (error) {
        console.error('执行功能失败:', error)
        ElMessage({
            message: '执行功能失败，请稍后重试',
            type: 'error'
        })
    } finally {
        submitting.value = false
    }
}

// 关闭对话框
const handleClose = () => {
    visible.value = false
    groups.value = []
    selectedChatIds.value = []
    selectAll.value = false
    currentPage.value = 1
    total.value = 0
    allGroupsCache.value = []
}
</script>
