<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="订单号" prop="order_no">
                    <el-input v-model="query.order_no" name="order_no" clearable />
                </el-form-item>
                <el-form-item label="会员ID" prop="member_id">
                    <el-input v-model="query.member_id" name="member_id" clearable />
                </el-form-item>
                <el-form-item label="状态" prop="status">
                    <el-select v-model="query.status" placeholder="请选择" clearable>
                        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <el-table :data="(data as any)?.data || []" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column prop="order_no" label="订单号" width="210" />
                <el-table-column prop="member_id" label="会员ID" width="100" />
                <el-table-column prop="currency" label="币种" width="80" />
                <el-table-column prop="amount" label="金额" />
                <el-table-column prop="actual_amount" label="到账" />
                <el-table-column label="上游" width="120">
                    <template #default="scope">
                        {{ scope.row.third_config?.name ?? scope.row.third_config_id ?? '-' }}
                    </template>
                </el-table-column>
                <el-table-column label="状态" width="100">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 2 ? 'success' : (scope.row.status === 0 ? 'warning' : 'info')">
                            {{ statusText(scope.row.status) }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" />
                <el-table-column label="操作" width="180">
                    <template #default="scope">
                        <el-button
                            v-if="scope.row.status === 0 || scope.row.status === 1"
                            type="success"
                            size="small"
                            @click="doAction(scope.row.id, 'complete', '确认标记该充值单为已完成并给会员加钱？')"
                        >
                            完成
                        </el-button>
                        <el-button
                            v-if="scope.row.status === 0"
                            type="danger"
                            size="small"
                            @click="doAction(scope.row.id, 'cancel', '确认取消该充值单？')"
                        >
                            取消
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
            <Paginate />
        </div>
    </div>
</template>

<script lang="ts" setup>
import { onMounted } from 'vue'
import { useGetList } from '@/composables/curd/useGetList'
import http from '@/support/http'
import { ElMessage, ElMessageBox } from 'element-plus'

const api = 'telegram/recharge-order'

const { data, query, search, reset, loading } = useGetList(api)

const statusOptions = [
    { value: 0, label: '待支付' },
    { value: 1, label: '已支付' },
    { value: 2, label: '已完成' },
    { value: 3, label: '已取消' },
    { value: 4, label: '已超时' },
    { value: 5, label: '失败' },
]

const statusText = (status: number) => statusOptions.find(i => i.value === status)?.label ?? String(status)

const doAction = async (id: number, action: string, tip: string) => {
    await ElMessageBox.confirm(tip, '提示', { type: 'warning' })
    await http.post(`telegram/recharge-order/${id}/${action}`)
    ElMessage.success('操作成功')
    reset()
}

// useGetList 不会自动发首次请求，必须在这里触发
onMounted(() => {
    search()
})
</script>
