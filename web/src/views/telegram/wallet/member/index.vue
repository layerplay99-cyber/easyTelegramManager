<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item :label="$t('wallet.telegramUserId')" prop="telegram_user_id">
                    <el-input v-model="query.telegram_user_id" name="telegram_user_id" clearable />
                </el-form-item>
                <el-form-item :label="$t('wallet.username')" prop="telegram_username">
                    <el-input v-model="query.telegram_username" name="telegram_username" clearable />
                </el-form-item>
                <el-form-item :label="$t('system.status')" prop="status">
                    <el-select v-model="query.status" :placeholder="$t('system.pleaseSelect')" clearable>
                        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <el-table :data="(data as any)?.data || []" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column prop="telegram_user_id" :label="$t('wallet.telegramUserId')" />
                <el-table-column prop="telegram_username" :label="$t('wallet.username')" />
                <el-table-column :label="$t('system.status')" width="100">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 1 ? 'success' : (scope.row.status === 2 ? 'danger' : 'info')">
                            {{ statusText(scope.row.status) }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column :label="$t('wallet.paymentPassword')" width="100">
                    <template #default="scope">
                        <el-tag :type="scope.row.payment_password ? 'success' : 'warning'">
                            {{ scope.row.payment_password ? $t('wallet.passwordSet') : $t('wallet.passwordUnset') }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="last_active_at" :label="$t('wallet.lastActive')" />
                <el-table-column prop="created_at" :label="$t('wallet.registerTime')" />
                <el-table-column :label="$t('system.operation')" width="220">
                    <template #default="scope">
                        <el-button size="small" @click="showWallets(scope.row)">{{ $t('wallet.wallets') }}</el-button>
                        <el-button
                            size="small"
                            :type="scope.row.status === 1 ? 'danger' : 'success'"
                            @click="toggleStatus(scope.row)"
                        >
                            {{ scope.row.status === 1 ? $t('wallet.freeze') : $t('wallet.unfreeze') }}
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
            <Paginate />
        </div>

        <el-dialog v-model="walletVisible" :title="$t('wallet.walletsOf', { id: current?.telegram_user_id })"
            width="640px">
            <el-table :data="wallets" v-loading="walletLoading" size="small">
                <el-table-column prop="currency" :label="$t('wallet.currency')" width="90" />
                <el-table-column prop="balance" :label="$t('wallet.balance')" />
                <el-table-column prop="frozen_balance" :label="$t('wallet.frozenBalance')" />
                <el-table-column prop="total_recharge" :label="$t('wallet.totalRecharge')" />
                <el-table-column prop="total_withdraw" :label="$t('wallet.totalWithdraw')" />
            </el-table>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue'
import { useGetList } from '@/composables/curd/useGetList'
import http from '@/support/http'
import { ElMessage } from 'element-plus'
import { t } from '@/support/helper'

const api = 'telegram/member'

const { data, query, search, reset, loading } = useGetList(api)

const statusOptions = [
    { value: 1, label: t('wallet.normal') },
    { value: 0, label: t('system.disabled') },
    { value: 2, label: t('wallet.frozen') },
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
    ElMessage.success(t('wallet.operateSuccess'))
    reset()
}

defineExpose({ search: computed(() => search) })

// useGetList 不会自动发首次请求，必须在这里触发
onMounted(() => {
    search()
})
</script>
