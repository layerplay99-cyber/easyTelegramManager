<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item :label="$t('wallet.fromCurrency')" prop="from_currency">
                    <el-input v-model="query.from_currency" name="from_currency" clearable />
                </el-form-item>
                <el-form-item :label="$t('wallet.toCurrency')" prop="to_currency">
                    <el-input v-model="query.to_currency" name="to_currency" clearable />
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <Operate :show="open" />
            <el-table :data="(data as any)?.data || []" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column prop="from_currency" :label="$t('wallet.fromCurrency')" width="100" />
                <el-table-column prop="to_currency" :label="$t('wallet.toCurrency')" width="100" />
                <el-table-column prop="rate" :label="$t('wallet.baseRate')" />
                <el-table-column prop="buy_rate" :label="$t('wallet.buyRate')" />
                <el-table-column prop="sell_rate" :label="$t('wallet.sellRate')" />
                <el-table-column prop="source" :label="$t('wallet.source')" width="120" />
                <el-table-column :label="$t('wallet.autoUpdate')" width="100">
                    <template #default="scope">
                        <el-tag :type="scope.row.auto_update ? 'success' : 'info'" size="small">
                            {{ scope.row.auto_update ? $t('system.yes') : $t('system.no') }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column :label="$t('system.status')" width="90">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 1 ? 'success' : 'info'">
                            {{ scope.row.status === 1 ? $t('system.enabled') : $t('system.disabled') }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column :label="$t('system.operation')" width="180">
                    <template #default="scope">
                        <Update @click="open(scope.row.id)" />
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
import { onMounted } from 'vue'

const api = 'telegram/exchange-rate'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

onMounted(() => {
    search()
    deleted(reset)
})
</script>
