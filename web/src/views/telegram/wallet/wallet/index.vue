<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item :label="$t('wallet.memberId')" prop="member_id">
                    <el-input v-model="query.member_id" name="member_id" clearable />
                </el-form-item>
                <el-form-item :label="$t('wallet.currency')" prop="currency">
                    <el-select v-model="query.currency" :placeholder="$t('system.pleaseSelect')" clearable>
                        <el-option v-for="c in currencies" :key="c" :label="c" :value="c" />
                    </el-select>
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <el-table :data="(data as any)?.data || []" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column prop="member_id" :label="$t('wallet.memberId')" />
                <el-table-column prop="currency" :label="$t('wallet.currency')" width="90" />
                <el-table-column prop="balance" :label="$t('wallet.balance')" />
                <el-table-column prop="frozen_balance" :label="$t('wallet.frozenBalance')" />
                <el-table-column prop="total_recharge" :label="$t('wallet.totalRecharge')" />
                <el-table-column prop="total_withdraw" :label="$t('wallet.totalWithdraw')" />
                <el-table-column :label="$t('system.status')" width="90">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 1 ? 'success' : 'info'">
                            {{ scope.row.status === 1 ? $t('wallet.normal') : $t('system.disabled') }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column :label="$t('system.operation')" width="200">
                    <template #default="scope">
                        <el-button type="warning" size="small" @click="openAdjust(scope.row)">
                            {{ $t('wallet.adjustBalance') }}
                        </el-button>
                        <el-button size="small" @click="toggleStatus(scope.row)">
                            {{ scope.row.status === 1 ? $t('system.disabled') : $t('system.enabled') }}
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
            <Paginate />
        </div>

        <el-dialog v-model="adjustVisible" :title="$t('wallet.adjustTitle', { id: current?.id })" width="480px">
            <el-form :model="adjustForm" label-width="100px">
                <el-form-item :label="$t('wallet.type')">
                    <el-radio-group v-model="adjustForm.type">
                        <el-radio-button label="reward">{{ $t('wallet.reward') }}</el-radio-button>
                        <el-radio-button label="deduct">{{ $t('wallet.deduct') }}</el-radio-button>
                    </el-radio-group>
                </el-form-item>
                <el-form-item :label="$t('wallet.amount')">
                    <el-input-number v-model="adjustForm.amount" :min="0.01" :precision="2" />
                </el-form-item>
                <el-form-item :label="$t('wallet.reason')">
                    <el-input v-model="adjustForm.title" :placeholder="$t('wallet.reasonPlaceholder')" />
                </el-form-item>
                <el-form-item :label="$t('wallet.remark')">
                    <el-input v-model="adjustForm.description" type="textarea" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="adjustVisible = false">{{ $t('system.cancel') }}</el-button>
                <el-button type="primary" :loading="submitting" @click="submitAdjust">{{ $t('system.confirm') }}</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup>
import { onMounted, ref } from 'vue'
import { useGetList } from '@/composables/curd/useGetList'
import http from '@/support/http'
import { ElMessage } from 'element-plus'
import { t } from '@/support/helper'

const api = 'telegram/wallet'

const { data, query, search, reset, loading } = useGetList(api)

const currencies = ['CNY', 'USDT', 'VND', 'THB']

const adjustVisible = ref(false)
const submitting = ref(false)
const current = ref<any>(null)
const adjustForm = ref({ type: 'reward', amount: 1, title: '', description: '' })

const openAdjust = (row: any) => {
    current.value = row
    adjustForm.value = { type: 'reward', amount: 1, title: '', description: '' }
    adjustVisible.value = true
}

const submitAdjust = async () => {
    if (!adjustForm.value.title) {
        ElMessage.warning(t('wallet.reasonRequired'))
        return
    }

    submitting.value = true
    try {
        await http.post(`telegram/wallet/${current.value.id}/adjust-balance`, adjustForm.value)
        ElMessage.success(t('wallet.adjustSuccess'))
        adjustVisible.value = false
        reset()
    } finally {
        submitting.value = false
    }
}

const toggleStatus = async (row: any) => {
    await http.post(`telegram/wallet/${row.id}/toggle-status`)
    ElMessage.success(t('wallet.operateSuccess'))
    reset()
}

// useGetList 不会自动发首次请求（loading 初始为 true），
// 必须在这里触发，否则列表一直转圈且 Network 里没有任何请求。
onMounted(() => {
    search()
})
</script>
