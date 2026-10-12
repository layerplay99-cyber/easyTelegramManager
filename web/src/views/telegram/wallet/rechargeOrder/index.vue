<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item :label="$t('wallet.orderNo')" prop="order_no">
                    <el-input v-model="query.order_no" name="order_no" clearable />
                </el-form-item>
                <el-form-item :label="$t('wallet.memberId')" prop="member_id">
                    <el-input v-model="query.member_id" name="member_id" clearable />
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
                <el-table-column prop="order_no" :label="$t('wallet.orderNo')" width="210" />
                <el-table-column prop="member_id" :label="$t('wallet.memberId')" width="100" />
                <el-table-column prop="currency" :label="$t('wallet.currency')" width="80" />
                <el-table-column prop="amount" :label="$t('wallet.amount')" />
                <el-table-column prop="actual_amount" :label="$t('wallet.received')" />
                <el-table-column :label="$t('wallet.upstream')" width="120">
                    <template #default="scope">
                        {{ scope.row.third_config?.name ?? scope.row.third_config_id ?? '-' }}
                    </template>
                </el-table-column>
                <el-table-column :label="$t('system.status')" width="100">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 2 ? 'success' : (scope.row.status === 0 ? 'warning' : 'info')">
                            {{ statusText(scope.row.status) }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" :label="$t('system.createTime')" />
                <el-table-column :label="$t('system.operation')" width="180">
                    <template #default="scope">
                        <el-button
                            v-if="scope.row.status === 0 || scope.row.status === 1"
                            type="success"
                            size="small"
                            @click="doAction(scope.row.id, 'complete', 'complete')"
                        >
                            {{ $t('wallet.complete') }}
                        </el-button>
                        <el-button
                            v-if="scope.row.status === 0"
                            type="danger"
                            size="small"
                            @click="doAction(scope.row.id, 'cancel', 'cancel')"
                        >
                            {{ $t('system.cancel') }}
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
import { t } from '@/support/helper'

const api = 'telegram/recharge-order'

const { data, query, search, reset, loading } = useGetList(api)

const statusOptions = [
    { value: 0, label: t('wallet.rechargePending') },
    { value: 1, label: t('wallet.rechargePaid') },
    { value: 2, label: t('wallet.completed') },
    { value: 3, label: t('wallet.canceled') },
    { value: 4, label: t('wallet.timeout') },
    { value: 5, label: t('wallet.failed') },
]

const statusText = (status: number) => statusOptions.find(i => i.value === status)?.label ?? String(status)

const doAction = async (id: number, action: string, tipKey: string) => {
    await ElMessageBox.confirm(t(`wallet.confirm_${tipKey}`), t('system.warning'), { type: 'warning' })
    await http.post(`telegram/recharge-order/${id}/${action}`)
    ElMessage.success(t('wallet.operateSuccess'))
    reset()
}

// useGetList 不会自动发首次请求，必须在这里触发
onMounted(() => {
    search()
})
</script>
