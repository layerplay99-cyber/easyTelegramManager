<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="类型" prop="type">
                    <el-select v-model="query.type" placeholder="请选择" clearable>
                        <el-option label="充值" value="recharge" />
                        <el-option label="提现" value="withdraw" />
                    </el-select>
                </el-form-item>
                <el-form-item label="币种" prop="currency">
                    <el-input v-model="query.currency" name="currency" clearable />
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <Operate :show="open" />
            <el-table :data="(data as any)?.data || []" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column label="类型" width="100">
                    <template #default="scope">{{ scope.row.type === 'recharge' ? '充值' : '提现' }}</template>
                </el-table-column>
                <el-table-column prop="level" label="等级" width="100" />
                <el-table-column prop="currency" label="币种" width="80" />
                <el-table-column prop="min_amount" label="单笔最小" />
                <el-table-column prop="max_amount" label="单笔最大" />
                <el-table-column prop="daily_amount" label="日累计额" />
                <el-table-column prop="monthly_amount" label="月累计额" />
                <el-table-column prop="daily_count" label="日笔数" width="90" />
                <el-table-column prop="monthly_count" label="月笔数" width="90" />
                <el-table-column label="状态" width="90">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 1 ? 'success' : 'info'">
                            {{ scope.row.status === 1 ? '启用' : '停用' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="200">
                    <template #default="scope">
                        <Update @click="open(scope.row.id)" />
                        <el-button size="small" @click="toggleStatus(scope.row)">
                            {{ scope.row.status === 1 ? '停用' : '启用' }}
                        </el-button>
                        <Destroy @click="destroy(api, scope.row.id)" />
                    </template>
                </el-table-column>
            </el-table>
            <Paginate />
        </div>

        <Dialog v-model="visible" :title="title" destroy-on-close>
            <Create @close="close(reset)" :primary="id" :api="api" />
        </Dialog>
    </div>
</template>

<script lang="ts" setup>
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import http from '@/support/http'
import { ElMessage } from 'element-plus'
import { onMounted } from 'vue'

const api = 'telegram/transaction-limit'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const toggleStatus = async (row: any) => {
    await http.post(`telegram/transaction-limit/${row.id}/toggle-status`)
    ElMessage.success('操作成功')
    reset()
}

onMounted(() => {
    search()
    deleted(reset)
})
</script>
