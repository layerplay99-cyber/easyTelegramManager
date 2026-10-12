<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item :label="$t('wallet.type')" prop="type">
                    <el-select v-model="query.type" :placeholder="$t('system.pleaseSelect')" clearable>
                        <el-option :label="$t('wallet.recharge')" value="recharge" />
                        <el-option :label="$t('wallet.withdraw')" value="withdraw" />
                    </el-select>
                </el-form-item>
                <el-form-item :label="$t('wallet.currency')" prop="currency">
                    <el-input v-model="query.currency" name="currency" clearable />
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <Operate :show="open" />
            <el-table :data="(data as any)?.data || []" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column :label="$t('wallet.type')" width="100">
                    <template #default="scope">
                        {{ scope.row.type === 'recharge' ? $t('wallet.recharge') : $t('wallet.withdraw') }}
                    </template>
                </el-table-column>
                <el-table-column prop="level" :label="$t('wallet.level')" width="100" />
                <el-table-column prop="currency" :label="$t('wallet.currency')" width="80" />
                <el-table-column prop="min_amount" :label="$t('wallet.minAmount')" />
                <el-table-column prop="max_amount" :label="$t('wallet.maxAmount')" />
                <el-table-column prop="daily_amount" :label="$t('wallet.dailyAmount')" />
                <el-table-column prop="monthly_amount" :label="$t('wallet.monthlyAmount')" />
                <el-table-column prop="daily_count" :label="$t('wallet.dailyCount')" width="90" />
                <el-table-column prop="monthly_count" :label="$t('wallet.monthlyCount')" width="90" />
                <el-table-column :label="$t('system.status')" width="90">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 1 ? 'success' : 'info'">
                            {{ scope.row.status === 1 ? $t('system.enabled') : $t('system.disabled') }}
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

const api = 'telegram/transaction-limit'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const toggleStatus = async (row: any) => {
    await http.post(`telegram/transaction-limit/${row.id}/toggle-status`)
    ElMessage.success(t('wallet.operateSuccess'))
    reset()
}

onMounted(() => {
    search()
    deleted(reset)
})
</script>
