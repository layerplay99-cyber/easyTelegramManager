<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="TG用户ID" prop="telegram_user_id">
                    <el-input v-model="query.telegram_user_id" name="telegram_user_id" clearable />
                </el-form-item>
                <el-form-item label="用户名" prop="telegram_username">
                    <el-input v-model="query.telegram_username" name="telegram_username" clearable />
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
                <el-table-column prop="telegram_user_id" label="TG用户ID" />
                <el-table-column prop="telegram_username" label="用户名" />
                <el-table-column label="状态" width="100">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 1 ? 'success' : (scope.row.status === 2 ? 'danger' : 'info')">
                            {{ statusText(scope.row.status) }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="支付密码" width="100">
                    <template #default="scope">
                        <el-tag :type="scope.row.payment_password ? 'success' : 'warning'">
                            {{ scope.row.payment_password ? '已设置' : '未设置' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="last_active_at" label="最后活跃" />
                <el-table-column prop="created_at" label="注册时间" />
                <el-table-column label="操作" width="220">
                    <template #default="scope">
                        <el-button size="small" @click="showWallets(scope.row)">钱包</el-button>
                        <el-button
                            size="small"
                            :type="scope.row.status === 1 ? 'danger' : 'success'"
                            @click="toggleStatus(scope.row)"
                        >
                            {{ scope.row.status === 1 ? '冻结' : '解冻' }}
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
            <Paginate />
        </div>

        <el-dialog v-model="walletVisible" :title="`会员 ${current?.telegram_user_id} 的钱包`" width="640px">
            <el-table :data="wallets" v-loading="walletLoading" size="small">
                <el-table-column prop="currency" label="币种" width="90" />
                <el-table-column prop="balance" label="可用余额" />
                <el-table-column prop="frozen_balance" label="冻结余额" />
                <el-table-column prop="total_recharge" label="累计充值" />
                <el-table-column prop="total_withdraw" label="累计提现" />
            </el-table>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue'
import { useGetList } from '@/composables/curd/useGetList'
import http from '@/support/http'
import { ElMessage } from 'element-plus'

const api = 'telegram/member'

const { data, query, search, reset, loading } = useGetList(api)

const statusOptions = [
    { value: 1, label: '正常' },
    { value: 0, label: '禁用' },
    { value: 2, label: '冻结' },
]

const statusText = (status: number) => statusOptions.find(i => i.value === status)?.label ?? String(status)

const walletVisible = ref(false)
const walletLoading = ref(false)
const wallets = ref<any[]>([])
const current = ref<any>(null)

const showWallets = async (row: any) => {
    current.value = row
    walletVisible.value = true
    walletLoading.value = true
    try {
        const res: any = await http.get(`telegram/wallet?member_id=${row.id}`)
        wallets.value = res?.data?.data ?? []
    } finally {
        walletLoading.value = false
    }
}

const toggleStatus = async (row: any) => {
    await http.post(`telegram/member/${row.id}/status`, { status: row.status === 1 ? 2 : 1 })
    ElMessage.success('操作成功')
    reset()
}

defineExpose({ search: computed(() => search) })

// useGetList 不会自动发首次请求，必须在这里触发
onMounted(() => {
    search()
})
</script>
