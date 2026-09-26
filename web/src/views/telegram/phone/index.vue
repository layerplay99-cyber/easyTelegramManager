<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="手机号" prop="phone">
                    <el-input v-model="(query as any).phone" name="phone" clearable />
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <div class="flex items-start justify-between">
                <div class="flex items-center gap-2">
                    <Operate :show="open" />
                    <div class="pt-5 pl-2 flex items-center gap-2">
                        <el-button type="primary" plain @click="handleExport">
                            <el-icon>
                                <Download />
                            </el-icon>导出
                        </el-button>
                        <div class="inline-block">
                            <el-upload :http-request="customUploadFile" :before-upload="beforeUpload" :limit="1"
                                :show-file-list="false" accept=".xlsx,.xls" class="inline-block"
                                :disabled="uploadProgress.visible">
                                <el-button type="primary" plain :disabled="uploadProgress.visible">
                                    <el-icon>
                                        <Upload />
                                    </el-icon>导入
                                </el-button>
                            </el-upload>
                        </div>
                        <!-- 下载模板 -->
                        <el-button type="primary" plain @click="handleExportTemplate">
                            <el-icon>
                                <Download />
                            </el-icon>下载模板
                        </el-button>
                    </div>
                </div>
            </div>
            <el-table :data="tableData" class="mt-3" v-loading="loading">
                <el-table-column prop="phone" label="手机号" />
                <el-table-column prop="created_at" label="创建时间" />
                <el-table-column label="操作" width="200">
                    <template #default="scope">
                        <Update @click="open(scope.row.id)" />
                        <Destroy @click="destroy(api, scope.row.id)" />
                    </template>
                </el-table-column>
            </el-table>
            <Paginate />
        </div>

        <Dialog v-model="visible" :title="title" destroy-on-close>
            <Create @close="close(reset)" :primary="id || undefined" :api="api" />
        </Dialog>

        <!-- 上传进度弹窗 -->
        <el-dialog v-model="uploadProgress.visible" title="导入进度" width="500px" :close-on-click-modal="false"
            :close-on-press-escape="false" :show-close="false">
            <div class="space-y-4">
                <!-- 进度条 -->
                <div>
                    <div class="flex justify-between mb-2">
                        <span>导入进度</span>
                        <span v-if="uploadProgress.total > 0">
                            {{ uploadProgress.current }}/{{ uploadProgress.total }} 条
                        </span>
                        <span v-else>准备中...</span>
                    </div>
                    <el-progress :percentage="uploadProgress.percentage"
                        :status="uploadProgress.status === 'done' ? 'success' : uploadProgress.status === 'error' ? 'exception' : undefined" />
                </div>

                <!-- 状态信息 -->
                <div class="text-sm text-gray-600">
                    <div v-if="uploadProgress.status === 'pending'">准备上传...</div>
                    <div v-else-if="uploadProgress.status === 'uploading'">正在上传文件...</div>
                    <div v-else-if="uploadProgress.status === 'processing'">
                        <span v-if="uploadProgress.total > 0">正在导入数据... ({{ uploadProgress.current }}/{{
                            uploadProgress.total
                        }})</span>
                        <span v-else>正在分析文件...</span>
                    </div>
                    <div v-else-if="uploadProgress.status === 'done'">
                        <div class="text-green-600">导入完成！</div>
                        <div class="mt-2">
                            <span class="text-green-600">成功：{{ uploadProgress.success }} 条</span>
                            <span v-if="uploadProgress.failed > 0" class="ml-4 text-red-600">
                                失败：{{ uploadProgress.failed }} 条
                            </span>
                        </div>
                    </div>
                    <div v-else-if="uploadProgress.status === 'error'">
                        <div class="text-red-600">操作失败</div>
                    </div>
                </div>

                <!-- 失败详情 -->
                <div v-if="uploadProgress.failedRows.length > 0" class="max-h-40 overflow-y-auto">
                    <el-divider content-position="left">失败详情</el-divider>
                    <div class="space-y-1">
                        <div v-for="(error, index) in uploadProgress.failedRows" :key="index"
                            class="text-sm text-red-600">
                            第 {{ error.row }} 行：{{ error.error }}
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <div class="text-center">
                    <el-button v-if="uploadProgress.status === 'done'" type="primary" @click="closeProgressDialog">
                        确定
                    </el-button>
                    <el-button v-else type="info" @click="cancelUpload">
                        取消
                    </el-button>
                </div>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import { Upload, Download } from '@element-plus/icons-vue'
import { useExcelDownload } from '@/composables/curd/useExcelDownload'
import { ElMessage } from 'element-plus'
import { useTelegramStore } from '@/stores/modules/telegram'

const api = 'telegram/phone'
const telegramStore = useTelegramStore()

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()
const { download } = useExcelDownload()
const uploadedFile = ref('')

// 上传进度相关状态
const uploadProgress = ref({
    visible: false,
    total: 0,
    current: 0,
    success: 0,
    failed: 0,
    failedRows: [],
    status: '',
    percentage: 0
})
const progressTimer = ref<NodeJS.Timeout | null>(null)
const taskId = ref('')

// 文件上传前验证
const beforeUpload = (file: any) => {
    const isExcel = file.type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ||
        file.type === 'application/vnd.ms-excel' ||
        file.name.endsWith('.xlsx') ||
        file.name.endsWith('.xls')
    if (!isExcel) {
        ElMessage.error('只支持 Excel 文件格式')
        return false
    }

    // 上传开始时显示进度条
    showProgressDialog()
    uploadProgress.value.status = 'uploading'
    ElMessage.info('开始上传文件...')

    return true
}

// 自定义上传处理
const customUploadFile = async (options: any) => {
    const { file } = options
    try {
        const result = await telegramStore.uploadPhoneFile(file)
        handleUploadSuccess(result)
    } catch (error) {
        console.error('上传失败:', error)
        handleUploadError(error)
    }
}

// 上传进度回调
const handleUploadProgress = (event: any) => {
    const percent = Math.round((event.loaded / event.total) * 100)
    uploadProgress.value.percentage = percent
    console.log('文件上传进度:', percent + '%')
}

// 上传成功回调
const handleUploadSuccess = (response: any) => {
    console.log('上传响应:', response)

    if (response.success && response.task_id) {
        // 异步上传，开始查询导入进度
        taskId.value = response.task_id
        uploadProgress.value.status = 'processing'
        // 重置进度为0，等待API返回真实进度
        uploadProgress.value.percentage = 0
        uploadProgress.value.current = 0
        uploadProgress.value.total = 0
        startProgressPolling()
        ElMessage.success('文件上传成功，开始导入数据...')
    } else if (response.success) {
        // 同步上传完成
        uploadProgress.value.status = 'done'
        uploadProgress.value.percentage = 100
        ElMessage.success(response.message || '导入成功')
        setTimeout(() => {
            closeProgressDialog()
            search()
        }, 2000)
    } else {
        uploadProgress.value.status = 'error'
        ElMessage.error(response.message || '上传失败')
        setTimeout(() => {
            closeProgressDialog()
        }, 2000)
    }
}

// 上传错误回调
const handleUploadError = (error: any) => {
    console.error('上传失败:', error)
    uploadProgress.value.status = 'error'
    ElMessage.error('文件上传失败')
    setTimeout(() => {
        closeProgressDialog()
    }, 2000)
}

const handleExport = () => {
    download(`/${api}/sheet`)
}

const handleExportTemplate = () => {
    download(`/${api}/template`)
}

// 显示进度弹窗
const showProgressDialog = () => {
    uploadProgress.value = {
        visible: true,
        total: 0,
        current: 0,
        success: 0,
        failed: 0,
        failedRows: [],
        status: 'pending',
        percentage: 0
    }
}

// 开始轮询进度
const startProgressPolling = () => {
    if (progressTimer.value) {
        clearInterval(progressTimer.value)
    }

    progressTimer.value = setInterval(async () => {
        await checkProgress()
    }, 1000) // 每秒查询一次

    // 立即查询一次
    checkProgress()
}

// 查询进度
const checkProgress = async () => {
    if (!taskId.value) return

    try {
        const result = await telegramStore.checkImportProgress(taskId.value)

        console.log('进度查询结果:', result)

        // 直接处理API返回的数据，不需要.data包装
        const data = result.data || result // 兼容两种格式

        if (data.status === 'not_found') {
            ElMessage.error('任务不存在')
            stopProgressPolling()
            closeProgressDialog()
            return
        }

        // 更新进度数据
        uploadProgress.value.total = data.total || 0
        uploadProgress.value.current = data.current || 0
        uploadProgress.value.success = data.success || 0
        uploadProgress.value.failed = data.failed || 0
        uploadProgress.value.failedRows = data.failedRows || []
        uploadProgress.value.status = data.status

        // 根据total和current计算进度百分比
        if (uploadProgress.value.total > 0) {
            uploadProgress.value.percentage = Math.round(
                (uploadProgress.value.current / uploadProgress.value.total) * 100
            )
        } else {
            // 如果还没有total数据，显示0%
            uploadProgress.value.percentage = 0
        }

        // 如果状态是完成，确保进度显示为100%
        if (data.status === 'done') {
            uploadProgress.value.percentage = 100
        }

        console.log(`调试信息 - total: ${uploadProgress.value.total}, current: ${uploadProgress.value.current}, percentage: ${uploadProgress.value.percentage}`)
        console.log('当前 uploadProgress 对象:', JSON.stringify(uploadProgress.value, null, 2))

        console.log(`导入进度: ${uploadProgress.value.current}/${uploadProgress.value.total} (${uploadProgress.value.percentage}%)`)

        // 如果完成，停止轮询
        if (data.status === 'done') {
            stopProgressPolling()
            ElMessage.success('导入完成！')
            // 延迟刷新列表
            setTimeout(() => {
                search()
            }, 1000)
        }

    } catch (error) {
        console.error('查询进度失败:', error)
        ElMessage.error('查询进度失败')
        stopProgressPolling()
    }
}

// 停止轮询
const stopProgressPolling = () => {
    if (progressTimer.value) {
        clearInterval(progressTimer.value)
        progressTimer.value = null
    }
}

// 关闭进度弹窗
const closeProgressDialog = () => {
    uploadProgress.value.visible = false
    stopProgressPolling()
    taskId.value = ''
}

// 取消上传
const cancelUpload = () => {
    stopProgressPolling()
    closeProgressDialog()
    ElMessage.info('已取消导入')
}

const tableData = computed(() => {
    return data.value && 'data' in data.value ? data.value.data : []
})

onMounted(() => {
    search()
    deleted(reset)

})

onUnmounted(() => {
    // 清理定时器
    stopProgressPolling()
})
</script>
