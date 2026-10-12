<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item :label="$t('wallet.name')" prop="name">
                    <el-input v-model="query.name" name="name" clearable />
                </el-form-item>
                <el-form-item :label="$t('wallet.type')" prop="type">
                    <el-select v-model="query.type" :placeholder="$t('system.pleaseSelect')" clearable>
                        <el-option v-for="item in typeOptions" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item>
                <el-form-item :label="$t('system.status')" prop="status">
                    <el-select v-model="query.status" :placeholder="$t('system.pleaseSelect')" clearable>
                        <el-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <Operate :show="open" />
            <el-table :data="(data as any)?.data || []" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column prop="name" :label="$t('wallet.channelName')" />
                <el-table-column prop="code" :label="$t('wallet.code')" />
                <el-table-column :label="$t('wallet.type')" width="100">
                    <template #default="scope">{{ typeText(scope.row.type) }}</template>
                </el-table-column>
                <el-table-column prop="currency" :label="$t('wallet.currency')" width="80" />
                <el-table-column prop="fee_rate" :label="$t('wallet.feeRate')" width="100" />
                <el-table-column prop="min_amount" :label="$t('wallet.minAmount')" />
                <el-table-column prop="max_amount" :label="$t('wallet.maxAmount')" />
                <el-table-column :label="$t('system.status')" width="100">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 1 ? 'success' : (scope.row.status === 2 ? 'warning' : 'info')">
                            {{ statusText(scope.row.status) }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column :label="$t('system.operation')" width="200">
                    <template #default="scope">
                        <Update @click="open(scope.row.id)" />
                        <el-button size="small" @click="toggleStatus(scope.row)">
                            {{ scope.row.status === 1 ? $t('system.disabled') : $t('system.enabled') }}
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
import { t } from '@/support/helper'
import { onMounted } from 'vue'

const api = 'telegram/payment-channel'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const typeOptions = [
    { value: 'recharge', label: t('wallet.recharge') },
    { value: 'withdraw', label: t('wallet.withdraw') },
    { value: 'both', label: t('wallet.rechargeAndWithdraw') },
]
const statusOptions = [
    { value: 1, label: t('system.enabled') },
    { value: 0, label: t('system.disabled') },
    { value: 2, label: t('wallet.maintenance') },
]

const typeText = (t: string) => typeOptions.find(i => i.value === t)?.label ?? t
const statusText = (s: number) => statusOptions.find(i => i.value === s)?.label ?? String(s)

const toggleStatus = async (row: any) => {
    await http.post(`telegram/payment-channel/${row.id}/toggle-status`)
    ElMessage.success(t('wallet.operateSuccess'))
    reset()
}

onMounted(() => {
    search()
    deleted(reset)
})
</script>
