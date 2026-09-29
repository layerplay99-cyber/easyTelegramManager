<template>
    <el-dialog v-model="visible" :title="$t('broadcast.title')" width="900px" @close="handleClose">
        <div v-loading="loading">
            <!-- 消息内容编辑区域 -->
            <div class="mb-4">
                <el-form :model="formData" label-width="100px">
                    <el-form-item :label="$t('broadcast.selectBot')" v-if="!botId">
                        <el-select v-model="formData.botId" :placeholder="$t('broadcast.selectBotPlaceholder')" style="width: 100%" @change="handleBotChange">
                            <el-option
                                v-for="bot in bots"
                                :key="bot.id"
                                :label="`${bot.username} (ID: ${bot.id})`"
                                :value="bot.id"
                            />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="发送通道">
                        <el-radio-group v-model="formData.channel">
                            <el-radio value="bot">Bot（纯文本 / 图文）</el-radio>
                            <el-radio value="user">Telegram 客服账号（可发自定义 / 动态表情）</el-radio>
                        </el-radio-group>
                    </el-form-item>
                    <el-form-item label="客服账号" v-if="formData.channel === 'user'">
                        <el-select v-model="formData.telegramUserId" placeholder="选择发送用的客服账号" style="width: 100%">
                            <el-option
                                v-for="u in telegramUsers"
                                :key="u.id"
                                :label="`${u.nickname || u.phone_number} (${u.app_id})`"
                                :value="u.id"
                            />
                        </el-select>
                        <div class="text-xs text-gray-400 mt-1">按该客服账号名下的群发送（app_id 匹配）</div>
                    </el-form-item>
                    <el-form-item label="消息模板">
                        <el-select v-model="formData.templateId" placeholder="可选：使用已保存的模板" clearable style="width: 100%">
                            <el-option v-for="tpl in templates" :key="tpl.id" :label="tpl.title" :value="tpl.id">
                                <span>{{ tpl.title }}</span>
                                <el-tag size="small" class="ml-2" :type="tpl.channel === 'user' ? 'success' : 'info'">
                                    {{ tpl.channel === 'user' ? '客服' : 'Bot' }}
                                </el-tag>
                            </el-option>
                        </el-select>
                        <div class="text-xs text-gray-400 mt-1">选了模板后以模板内容为准，下方文本会被忽略</div>
                    </el-form-item>
                    <el-form-item :label="$t('groupSelect.messageType')" v-if="!formData.templateId">
                        <el-select v-model="formData.type" :placeholder="$t('groupSelect.messageTypePlaceholder')" style="width: 200px">
                            <el-option :label="$t('groupSelect.textType')" value="text" />
                            <el-option :label="$t('groupSelect.photoType')" value="photo" />
                        </el-select>
                    </el-form-item>
                    <el-form-item :label="$t('broadcast.photoUrl')" v-if="formData.type === 'photo'">
                        <Upload
                            class="w-28"
                            action="/upload/image"
                            :show-file-list="false"
                            name="image"
                            v-model="formData.photo"
                            @update:modelValue="handlePhotoChange"
                        >
                            <div class="flex flex-col">
                                <img :src="formData.photo" v-if="formData.photo" class="w-28 h-28 object-cover" />
                                <div v-else class="flex justify-center items-center w-28 h-28 border border-dashed border-gray-300 hover:border-blue-500 cursor-pointer">
                                    <div class="text-center">
                                        <el-icon class="text-2xl text-gray-400"><Plus /></el-icon>
                                        <div class="text-xs text-gray-400 mt-1">{{ $t('broadcast.uploadPhoto') }}</div>
                                    </div>
                                </div>
                            </div>
                        </Upload>
                        <div class="text-xs text-gray-400 mt-1">{{ $t('broadcast.photoTip') }}</div>
                        <el-input
                            v-model="formData.photo"
                            :placeholder="$t('broadcast.photoUrlPlaceholder')"
                            class="mt-2"
                        />
                    </el-form-item>
                    <el-form-item :label="formData.type === 'photo' ? $t('broadcast.caption') : $t('broadcast.messageContent')" v-if="!formData.templateId">
                        <el-input
                            v-model="formData.text"
                            type="textarea"
                            :rows="6"
                            :placeholder="formData.type === 'photo' ? $t('broadcast.captionPlaceholder') : $t('broadcast.messagePlaceholder')"
                            maxlength="4096"
                            show-word-limit
                        />
                    </el-form-item>
                </el-form>
            </div>

            <div class="mb-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <el-checkbox v-model="selectAll" @change="handleSelectAllChange">
                        {{ $t('broadcast.selectAll') }}
                    </el-checkbox>
                    <el-select
                        v-model="formData.groupId"
                        :placeholder="$t('broadcast.selectGroupCategory')"
                        clearable
                        style="width: 200px"
                    >
                        <el-option
                            v-for="group in groupCategories"
                            :key="group.id"
                            :label="group.name"
                            :value="group.id"
                        />
                    </el-select>
                    <el-input
                        v-model="searchKeyword"
                        :placeholder="$t('broadcast.searchGroupName')"
                        clearable
                        style="width: 200px"
                        @clear="handleSearch"
                        @keyup.enter="handleSearch"
                    >
                        <template #append>
                            <el-button :icon="Search" @click="handleSearch" />
                        </template>
                    </el-input>
                    <el-button
                        type="primary"
                        @click="handleSubmit"
                        :loading="submitting"
                        :disabled="!canSubmit"
                    >
                        {{ $t('broadcast.confirmSend') }} ({{ getSelectedCount() }})
                    </el-button>
                </div>
                <div class="text-sm text-gray-500">
                    {{ getSelectedDescription() }}
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
    </el-dialog>
</template>

<script lang="ts" setup>
import { ref, watch, nextTick, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Search } from '@element-plus/icons-vue'
import http from '@/support/http'
import { useGroup } from '@/stores/modules/telegram/group'
import { useBotStore } from '@/stores/modules/telegram/botApi'
import { useI18n } from 'vue-i18n'

interface Props {
    modelValue: boolean
    botId?: number | null
}

const props = defineProps<Props>()
const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
    (e: 'success'): void
}>()

const { t } = useI18n()
const groupStore = useGroup()
const botStore = useBotStore()

const visible = ref(false)
const loading = ref(false)
const submitting = ref(false)
const groups = ref<any[]>([])
const bots = ref<any[]>([])
const groupCategories = ref<any[]>([])
const selectedChatIds = ref<string[]>([])
const selectAll = ref(false)
const searchKeyword = ref('')
const tableRef = ref()

// 表单数据
const formData = ref({
    botId: null as number | null,
    groupId: null as number | null,
    type: 'text' as 'text' | 'photo',
    text: '',
    photo: '',
    channel: 'bot' as 'bot' | 'user',
    telegramUserId: null as number | null,
    templateId: null as number | null,
})

// 模板与客服账号（选择发送通道 / 模板用）
const templates = ref<any[]>([])
const telegramUsers = ref<any[]>([])

const loadTemplates = async () => {
    try {
        const r = await http.get('telegram/message/template', { limit: 100 })
        templates.value = r.data.data?.data || r.data.data || []
    } catch (e) {
        templates.value = []
    }
}

const loadTelegramUsers = async () => {
    try {
        const r = await http.get('telegram/telegram/api/user', { limit: 100 })
        telegramUsers.value = r.data.data?.data || r.data.data || []
    } catch (e) {
        telegramUsers.value = []
    }
}

// 分页相关
const currentPage = ref(1)
const pageSize = ref(10)
const total = ref(0)

// 是否可以提交
const canSubmit = computed(() => {
    const hasBot = !!formData.value.botId
    // 满足以下任意一个条件即可：选择了分组、全选、或勾选了单个/多个群组
    const hasTarget = !!(formData.value.groupId || selectAll.value || selectedChatIds.value.length > 0)
    // 客服账号通道必须指定账号（bot 通道用 botId）
    const hasSender = formData.value.channel === 'user' ? !!formData.value.telegramUserId : true

    // 选了模板就以模板内容为准
    if (formData.value.templateId) {
        return hasBot && hasSender && hasTarget
    }

    if (formData.value.type === 'photo') {
        return hasBot && hasSender && !!formData.value.photo.trim() && hasTarget
    }

    return hasBot && hasSender && !!formData.value.text.trim() && hasTarget
})

// 获取选中数量（用于按钮显示）
const getSelectedCount = () => {
    if (selectAll.value) {
        return t('broadcast.allGroups')
    }
    if (formData.value.groupId) {
        const selectedGroup = groupCategories.value.find(g => g.id === formData.value.groupId)
        return selectedGroup ? selectedGroup.name : t('broadcast.groupCategory')
    }
    return selectedChatIds.value.length
}

// 获取选择描述（用于右侧显示）
const getSelectedDescription = () => {
    if (selectAll.value) {
        return t('broadcast.selected') + ' ' + t('broadcast.allGroups') + ' ' + t('broadcast.groups')
    }
    if (formData.value.groupId) {
        const selectedGroup = groupCategories.value.find(g => g.id === formData.value.groupId)
        const groupName = selectedGroup ? selectedGroup.name : t('broadcast.groupCategory')
        return t('broadcast.selected') + ' ' + groupName
    }
    return t('broadcast.selected') + ' ' + selectedChatIds.value.length + ' ' + t('broadcast.groups')
}

// 加载群分组列表
const loadGroupCategories = async () => {
    try {
        const response = await groupStore.getGroupGroupList()
        if (response && response.data) {
            groupCategories.value = response.data.data || response.data || []
        }
    } catch (error) {
        console.error('加载群分组失败:', error)
    }
}

// 搜索处理
const handleSearch = () => {
    currentPage.value = 1
    loadGroups()
}

// 图片上传成功后的回调
const handlePhotoChange = (newValue: string) => {
    if (newValue) {
        ElMessage.success(t('broadcast.uploadSuccess'))
    }
}

// 监听 modelValue 变化
watch(() => props.modelValue, (newVal) => {
    visible.value = newVal
    if (newVal) {
        currentPage.value = 1
        selectedChatIds.value = []
        selectAll.value = false
        searchKeyword.value = ''
        formData.value.type = 'text'
        formData.value.text = ''
        formData.value.photo = ''
        formData.value.groupId = null
        formData.value.channel = 'bot'
        formData.value.telegramUserId = null
        formData.value.templateId = null

        // 加载群分组列表
        loadGroupCategories()

        // 模板与客服账号（选通道 / 选模板用）
        loadTemplates()
        loadTelegramUsers()

        if (props.botId) {
            formData.value.botId = props.botId
            loadGroups()
        } else {
            formData.value.botId = null
            loadBots()
        }
    }
})

watch(visible, (newVal) => {
    emit('update:modelValue', newVal)
})

// 加载Bot列表
const loadBots = async () => {
    loading.value = true
    try {
        const response = await fetch(`${import.meta.env.VITE_BASE_URL}/telegram/bots?limit=1000`, {
            headers: {
                'Authorization': `Bearer ${localStorage.getItem('catchadmin_auth_token')}`,
                'Content-Type': 'application/json'
            }
        })
        const result = await response.json()
        bots.value = result.data?.data || []
    } catch (error) {
        console.error('加载Bot列表失败:', error)
        ElMessage.error(t('broadcast.loadBotsFailed'))
    } finally {
        loading.value = false
    }
}

// Bot选择改变
const handleBotChange = () => {
    currentPage.value = 1
    selectedChatIds.value = []
    selectAll.value = false
    loadGroups()
}

// 加载群组列表
const loadGroups = async () => {
    if (!formData.value.botId) return

    loading.value = true

    try {
        // 使用后端分页
        const response = await groupStore.selectGroup(
            formData.value.botId,
            0,
            currentPage.value,
            pageSize.value,
            searchKeyword.value
        )

        if (response.data) {
            // 处理返回的数据结构
            const responseData = response.data.data || response.data || []
            groups.value = Array.isArray(responseData) ? responseData : []
            total.value = response.data.total || response.total || 0

            if (groups.value.length === 0 && currentPage.value === 1) {
                ElMessage({
                    message: t('broadcast.syncFirst'),
                    type: 'warning'
                })
            }

            // 更新表格选中状态
            await nextTick()
            if (tableRef.value && groups.value.length > 0) {
                groups.value.forEach(row => {
                    if (selectedChatIds.value.includes(row.chat_id)) {
                        tableRef.value.toggleRowSelection(row, true)
                    }
                })
            }
        } else {
            ElMessage({
                message: response.message || t('broadcast.loadGroupsFailed'),
                type: 'error'
            })
            groups.value = []
            total.value = 0
        }
    } catch (error) {
        console.error('加载群组失败:', error)
        ElMessage({
            message: t('broadcast.loadGroupsRetry'),
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
    if (!selectAll.value) {
        selectedChatIds.value = selection.map(item => item.chat_id)
    }
}

// 处理全选变化
const handleSelectAllChange = async (val: boolean) => {
    if (val) {
        // 全选时清空具体的选择
        selectedChatIds.value = []

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

// 提交发送
const handleSubmit = async () => {
    if (!formData.value.botId) {
        ElMessage({
            message: t('broadcast.selectBotRequired'),
            type: 'warning'
        })
        return
    }

    if (formData.value.channel === 'user' && !formData.value.telegramUserId) {
        ElMessage({
            message: '请选择发送用的客服账号',
            type: 'warning'
        })
        return
    }

    if (!formData.value.templateId) {
        if (formData.value.type === 'photo') {
            if (!formData.value.photo.trim()) {
                ElMessage({
                    message: t('broadcast.photoRequired'),
                    type: 'warning'
                })
                return
            }
        } else {
            if (!formData.value.text.trim()) {
                ElMessage({
                    message: t('broadcast.messageRequired'),
                    type: 'warning'
                })
                return
            }
        }
    }

    // 验证是否选择了目标（分组、全选或具体群组）
    if (!formData.value.groupId && !selectAll.value && selectedChatIds.value.length === 0) {
        ElMessage({
            message: t('broadcast.selectGroupRequired'),
            type: 'warning'
        })
        return
    }

    submitting.value = true

    try {
        // 如果是全选，传递 "all"，否则传递选中的 chat_id 列表
        const chatIds = selectAll.value ? 'all' : selectedChatIds.value

        const payload: Record<string, any> = {
            botId: formData.value.botId,
            groupId: formData.value.groupId || 0,
            chatIds: chatIds as any,
            type: formData.value.type,
            text: formData.value.text,
            photo: formData.value.photo,
            caption: formData.value.type === 'photo' ? formData.value.text : undefined
        }

        // 客服账号通道：可发自定义 / 动态表情（后端会校验含表情的内容必须走该通道）
        if (formData.value.channel === 'user') {
            payload.channel = 'user'
            payload.telegram_user_id = formData.value.telegramUserId
        }

        if (formData.value.templateId) {
            payload.template_id = formData.value.templateId
        }

        const result = await botStore.sendGroupMessage(payload as any)

        if (result.success) {
            // 如果 data.code 是 10005，不显示成功消息，但也不关闭弹框
            if (result.data?.code === 10005) {
                ElMessage({
                    message: result.data?.message || result.message || t('broadcast.partialFailed'),
                    type: 'error'
                })
            } else {
                ElMessage({
                    message: result.message || t('broadcast.sendSuccess'),
                    type: 'success'
                })
            }
            emit('success')
        } else {
            ElMessage({
                message: result.message || t('broadcast.sendFailed'),
                type: 'error'
            })
        }
    } catch (error) {
        console.error('发送消息失败:', error)
        ElMessage({
            message: t('broadcast.sendRetry'),
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
    searchKeyword.value = ''
    currentPage.value = 1
    total.value = 0
    formData.value.type = 'text'
    formData.value.text = ''
    formData.value.photo = ''
    formData.value.groupId = null
}
</script>
