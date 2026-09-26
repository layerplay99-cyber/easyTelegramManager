<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="APP ID" prop="app_id">
                    <el-input v-model="query.app_id" name="app_id" clearable />
                </el-form-item>
                <el-form-item label="APP Hash" prop="app_hash">
                    <el-input v-model="query.app_hash" name="app_hash" clearable />
                </el-form-item>
                <el-form-item label="电话号码" prop="phone_number">
                    <el-input v-model="query.phone_number" name="phone_number" clearable />
                </el-form-item>
                <el-form-item label="昵称" prop="nickname">
                    <el-input v-model="query.nickname" name="nickname" clearable />
                </el-form-item>
                <el-form-item label="登录状态" prop="login_status">
                    <el-select v-model="query.login_status" placeholder="请选择" clearable>
                        <el-option v-for="item in options" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <div class="flex justify-end gap-2 mb-2">
                <el-upload
                    :show-file-list="false"
                    :http-request="customUploadFile"
                    accept=".xls,.xlsx,.csv"
                    :before-upload="beforeUpload"
                >
                    <el-button :icon="Upload" type="success">导入</el-button>
                </el-upload>
                <el-button :icon="Download" type="warning" @click="() => handleExport('telegram/telegram/api/user')">导出</el-button>
            </div>
            <Operate :show="open" />
            <el-table :data="(data as any)?.data || []" v-loading="loading">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column prop="app_id" label="app_id" />
                <el-table-column prop="app_hash" label="app_hash" />
                <el-table-column prop="phone_number" label="手机号" />
                <el-table-column prop="nickname" label="昵称" />
                <el-table-column prop="service_peoples_count" label="入群数" />
                <!-- <el-table-column prop="code" label="验证码" /> -->
                <el-table-column prop="login_status" label="登录状态">
                    <template #default="scope">
                        <el-tag :type="getLoginStatusType(scope.row.login_status)">
                            {{ getLoginStatusText(scope.row.login_status) }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="status" label="用户状态">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 1 ? 'success' : 'info'">
                            {{ scope.row.status === 1 ? '启用' : '禁用' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" />
                <el-table-column label="操作" width="500">
                    <template #default="scope">
                        <div class="flex flex-col gap-2">
                            <!-- 第一排：原有按钮 -->
                            <div class="flex gap-1">
                                <el-button
                                    v-if="scope.row.login_status !== 1"
                                    type="success"
                                    size="small"
                                    @click="submitLogin(scope.row)"
                                >
                                    <Icon name="right-to-bracket" className="w-4 h-4 mr-1" /> 登录
                                </el-button>
                                <el-button
                                    v-else
                                    type="warning"
                                    size="small"
                                    @click="submitLogout(scope.row)"
                                >
                                    <Icon name="right-from-bracket" className="w-4 h-4 mr-1" /> 注销
                                </el-button>
                                <el-button type="primary" size="small" @click="open(scope.row.id)">
                                    <Icon name="pen-to-square" className="w-4 h-4 mr-1" /> 编辑
                                </el-button>
                                <el-button type="info" size="small" @click="openFeatureDialog(scope.row)">
                                    <Icon name="puzzle-piece" className="w-4 h-4 mr-1" /> 功能
                                </el-button>
                                <el-button type="success" size="small" @click="syncGroups(scope.row.app_id)">
                                    <Icon name="rotate" className="w-4 h-4 mr-1" /> 同步群
                                </el-button>
                                <el-button type="danger" size="small" @click="destroy(api, scope.row.id)">
                                    <Icon name="trash" className="w-4 h-4 mr-1" /> 删除
                                </el-button>
                            </div>
                            <!-- 第二排：功能按钮 -->
                            <div v-if="scope.row.features_binds && scope.row.features_binds.length > 0" class="flex gap-1">
                                <el-button
                                    v-for="bind in scope.row.features_binds"
                                    :key="bind.id"
                                    type="primary"
                                    size="small"
                                    plain
                                    @click="handleFeatureAction(scope.row, bind)"
                                >
                                    {{ bind.feature.name }}
                                </el-button>
                            </div>
                        </div>
                    </template>
                </el-table-column>
            </el-table>
            <Paginate />
        </div>

        <Dialog v-model="visible" :title="title" destroy-on-close>
            <Create @close="close(reset)" :primary="id" :api="api" />
        </Dialog>

        <!-- 登录相关对话框 -->
        <LoginDialogs
            ref="loginDialogsRef"
            @login-success="search"
            @refresh-list="search"
        />

        <!-- 功能配置对话框 -->
        <FeatureConfig
            v-model="featureDialogVisible"
            :target-id="featureTargetId"
            :bot-id="featureBotId"
            :initial-features="currentUserFeatures"
            category="realMan"
            @saved="handleFeatureSaved"
        />

        <!-- 群选择对话框 -->
        <GroupSelectDialog
            v-model="groupSelectDialog.visible"
            :row="groupSelectDialog.currentRow"
            :bind="groupSelectDialog.currentBind"
            @success="handleGroupSelectSuccess"
        />
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import { useExcelDownload } from '@/composables/curd/useExcelDownload'
import { Upload, Download } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { useTelegramStore } from '@/stores/modules/telegram'
import echo from '@/support/echo'
import Icon from '@/components/icon/index.vue'
import FeatureConfig from '@/components/telegram/FeatureConfig.vue'
import GroupSelectDialog from './GroupSelectDialog.vue'
import LoginDialogs from './LoginDialogs.vue'
import { useLoginActions } from './useLoginActions'

const api = 'telegram/telegram/api/user'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

// 导入/导出（原 tusers 表的 Excel 功能已并入本页）
const telegramStore = useTelegramStore()
const { download: handleExport } = useExcelDownload()

const beforeUpload = (file: File) => {
    const validTypes = ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv']
    if (!validTypes.includes(file.type)) {
        ElMessage({ message: '文件格式不正确，请上传Excel或CSV文件', type: 'error' })
        return false
    }
    return true
}

const customUploadFile = async (options: any) => {
    const { file } = options
    try {
        const result = await telegramStore.uploadFile(file)
        if (result.success) {
            ElMessage({ message: '文件上传成功', type: 'success' })
            search()
        } else {
            ElMessage({ message: result.message || '上传失败', type: 'error' })
        }
    } catch (error) {
        console.error('上传失败:', error)
        ElMessage({ message: '上传失败，请稍后重试', type: 'error' })
    }
}

const tableData = computed(() => data.value?.data)

// 登录相关组件和功能
const loginDialogsRef = ref()
const loginActions = useLoginActions()

// 功能配置对话框相关
const featureDialogVisible = ref(false)
const featureTargetId = ref<number | null>(null)
const featureBotId = ref<number | null>(null)
const currentUserFeatures = ref<any[]>([])

const openFeatureDialog = (row: any) => {
    featureTargetId.value = null
    featureBotId.value = parseInt(row.app_id)
    currentUserFeatures.value = row.features_binds || []
    featureDialogVisible.value = true
}

const handleFeatureSaved = () => {
    search()
}

// 群选择对话框相关数据
const groupSelectDialog = ref({
    visible: false,
    currentRow: null as any,
    currentBind: null as any
})

// 打开群选择对话框
const openGroupSelectDialog = (row: any, bind: any) => {
    groupSelectDialog.value.currentRow = row
    groupSelectDialog.value.currentBind = bind
    groupSelectDialog.value.visible = true
}

// 群选择成功回调
const handleGroupSelectSuccess = () => {
    // 可以在这里刷新列表或执行其他操作
    console.log('群选择操作成功')
}

// 处理功能按钮点击
const handleFeatureAction = (row: any, bind: any) => {
    console.log('功能按钮点击:', row, bind)
    // 根据不同的功能 handler 执行不同的操作
    // 对于表情包采集等功能，打开群选择对话框
    openGroupSelectDialog(row, bind)
}

const options = [
    { label: '已登录', value: 1 },
    { label: '未登录', value: 0 },
    { label: '等待验证码', value: 2 },
    { label: '登录过期', value: 3 },
    { label: '等待登录完成', value: 4 },
]

// 获取登录状态文本
const getLoginStatusText = (status: number) => {
    const statusMap = {
        1: '已登录',
        0: '未登录',
        2: '等待验证',
        3: '登录过期',
        4: '等待登录完成',
    }
    return statusMap[status as keyof typeof statusMap] || '未知状态'
}

// 获取登录状态标签类型
const getLoginStatusType = (status: number) => {
    const typeMap = {
        1: 'success',  // 已登录 - 绿色
        0: 'info',     // 未登录 - 灰色
        2: 'warning',  // 等待验证 - 橙色
        3: 'danger'    // 登录过期 - 红色
    }
    return typeMap[status as keyof typeof typeMap] || 'info'
}

// 登录操作
const submitLogin = (user: any) => {
    loginActions.submitLogin(user, {
        onNeed2FA: (userId) => loginDialogsRef.value?.open2FADialog(userId),
        onShowQrCode: (userId, qrSvg) => loginDialogsRef.value?.openLoginDialog(userId, qrSvg)
    })
}

// 注销操作
const submitLogout = (user: any) => {
    loginActions.submitLogout(user, () => search())
}

// 同步群组
const syncGroups = (appId: number) => {
    loginActions.syncGroups(appId, () => search())
}

// WebSocket 登录状态监听
const loginStatues = () => {
    const token = localStorage.getItem('catchadmin_auth_token');
    if (!token) {
        console.error('未找到用户token，无法订阅私有频道');
        return;
    }

    const channelName = 'telegramUser-status';

    try {
        const channel = echo.private(channelName);

        channel.subscribed(() => {
            console.log(`成功订阅私有频道: ${channelName}`);
        });

        channel.error((error: any) => {
            console.error(`私有频道 ${channelName} 连接错误:`, error);
        });

        channel.listen('.TelegramUserLoginStatus', (e: any) => {
            console.log('收到 TelegramUserLoginStatus 事件:', e);

            if (e.id !== undefined && e.login_status !== undefined) {
                loginDialogsRef.value?.handleLoginStatusBroadcast(e)

                // 更新表格中的用户状态
                if (data.value && 'data' in data.value && Array.isArray((data.value as any).data)) {
                    (data.value as any).data = (data.value as any).data.map((item: any) => {
                        if (item.id === e.id) {
                            return { ...item, login_status: e.login_status }
                        }
                        return item
                    })
                }
            }
        });

    } catch (error) {
        console.error('创建私有频道失败:', error);
    }
}

onMounted(() => {
    search()
    loginStatues()
    deleted(reset)
})

onUnmounted(() => {
    loginDialogsRef.value?.stopLoginStatusPolling()
})
</script>

<style scoped>
:deep(.success-message) {
    background-color: #f0f9ff !important;
    border-color: #10b981 !important;
    color: #065f46 !important;
}

:deep(.success-message .el-message__icon) {
    color: #10b981 !important;
}

:deep(.error-message) {
    background-color: #fef2f2 !important;
    border-color: #ef4444 !important;
    color: #991b1b !important;
}

:deep(.error-message .el-message__icon) {
    color: #ef4444 !important;
}

:deep(.warning-message) {
    background-color: #fffbeb !important;
    border-color: #f59e0b !important;
    color: #92400e !important;
}

:deep(.warning-message .el-message__icon) {
    color: #f59e0b !important;
}
</style>
