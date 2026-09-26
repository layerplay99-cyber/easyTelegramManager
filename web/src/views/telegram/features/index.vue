<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="功能名称" prop="name">
                    <el-input v-model="(query as any).name" name="name" clearable />
                </el-form-item>
                <el-form-item label="是否启用" prop="enabled">
                    <el-select v-model="(query as any).enabled" placeholder="请选择" clearable multiple>
                        <el-option v-for="item in enabled" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <Operate :show="open" />
            <el-table :data="tableData" class="mt-3" v-loading="loading">
                <el-table-column prop="name" label="功能名称" />
                <el-table-column prop="category" label="功能归属" />
                <el-table-column prop="type" label="功能类型" />
                <el-table-column prop="requestType" label="请求类型" />
                <el-table-column prop="location" label="数据来源" />
                <el-table-column prop="feature" label="功能标识" />
                <el-table-column prop="description" label="功能描述" width="200">
                    <template #default="scope">
                        <el-tooltip
                            :content="scope.row.description || ''"
                            placement="top"
                            :disabled="!scope.row.description"
                            effect="dark"
                        >
                            <span class="description-text">{{ scope.row.description || '' }}</span>
                        </el-tooltip>
                    </template>
                </el-table-column>
                <el-table-column v-if="isSuperAdmin" prop="handler" label="处理类" />
                <el-table-column prop="config" label="配置项" />
                <el-table-column label="是否启用" width="100">
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
            <Create @close="close(search)" :primary="id" :api="api" />
        </Dialog>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted } from 'vue'
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import { useUserStore } from '@/stores/modules/user'
import http from '@/support/http'
import Message from '@/support/message'

const api = 'telegram/features'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const userStore = useUserStore()
const isSuperAdmin = userStore.isSuperAdmin()

const enabled = [
    { label: '启用', value: 1 },
    { label: '禁用', value: 0 }
]

// 值映射配置
const categoryMap = {
    'system': '系统',
    'custom': '客户端',
    'bot': '机器人',
    'realMan': '真人'
}

const typeMap = {
    'command': '指令',
    'ocr': '图片',
    'notify': '通知',
    'interaction': '交互'
}

const requestTypeMap = {
    'message': '消息',
    'callback_query': '按钮回调',
    'inline_query': '内联回调'
}

const locationMap = {
    'local': '本地',
    'external': '外部'
}

const enabledMap = {
    1: '启用',
    0: '禁用'
}

// 转换表格数据，将 value 转换为 label 显示
const tableData = computed(() => {
    if (!(data.value as any)?.data) return []

    return (data.value as any).data.map((item: any) => ({
        ...item,
        category: categoryMap[item.category as keyof typeof categoryMap] || item.category,
        type: typeMap[item.type as keyof typeof typeMap] || item.type,
        requestType: requestTypeMap[item.requestType as keyof typeof requestTypeMap] || item.requestType,
        location: locationMap[item.location as keyof typeof locationMap] || item.location,
        switchLoading: false // 添加开关加载状态
    }))
})

// 处理启用状态变化
const handleEnabledChange = async (row: any) => {
    row.switchLoading = true
    try {
        // 直接调用更新API，只传递id和enabled状态
        await http.put(`${api}/${row.id}`, { enabled: row.enabled })
        Message.success('状态更新成功')
        // 更新成功后刷新数据
        search()
    } catch (error) {
        // 如果更新失败，恢复原状态
        row.enabled = row.enabled === 1 ? 0 : 1
        Message.error('状态更新失败')
    } finally {
        row.switchLoading = false
    }
}

onMounted(() => {
    search()
    deleted(reset)
})
</script>

<style scoped>
.description-text {
    display: inline-block;
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: top;
}
</style>
