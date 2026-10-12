<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item :label="$t('wallet.memberId')" prop="member_id">
                    <el-input v-model="query.member_id" name="member_id" clearable />
                </el-form-item>
                <el-form-item :label="$t('wallet.telegramUserId')" prop="telegram_user_id">
                    <el-input v-model="query.telegram_user_id" name="telegram_user_id" clearable />
                </el-form-item>
                <el-form-item :label="$t('wallet.type')" prop="type">
                    <el-select v-model="query.type" :placeholder="$t('system.pleaseSelect')" clearable>
                        <el-option v-for="item in types" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item>
                <el-form-item :label="$t('wallet.currency')" prop="currency">
                    <el-input v-model="query.currency" name="currency" clearable />
                </el-form-item>
                <el-form-item :label="$t('wallet.orderNo')" prop="order_no">
                    <el-input v-model="query.order_no" name="order_no" clearable />
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <el-table :data="(data as any)?.data || []" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column :label="$t('wallet.member')" width="160">
                    <template #default="scope">
                        {{ scope.row.member?.telegram_username || ('#' + scope.row.member_id) }}
                    </template>
                </el-table-column>
                <el-table-column :label="$t('wallet.type')" width="100">
                    <template #default="scope">
                        <el-tag :type="scope.row.amount >= 0 ? 'success' : 'danger'" size="small">
                            {{ typeText(scope.row.type) }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="currency" :label="$t('wallet.currency')" width="80" />
                <el-table-column prop="amount" :label="$t('wallet.change')" />
                <el-table-column prop="balance_before" :label="$t('wallet.balanceBefore')" />
                <el-table-column prop="balance_after" :label="$t('wallet.balanceAfter')" />
                <el-table-column prop="frozen_before" :label="$t('wallet.frozenBefore')" />
                <el-table-column prop="frozen_after" :label="$t('wallet.frozenAfter')" />
                <el-table-column prop="order_no" :label="$t('wallet.relatedOrder')" width="200" />
                <el-table-column prop="created_at" :label="$t('wallet.time')" />
            </el-table>
            <Paginate />
        </div>
    </div>
</template>

<script lang="ts" setup>
import { onMounted, ref } from 'vue'
import { useGetList } from '@/composables/curd/useGetList'
import http from '@/support/http'

const api = 'telegram/ledger'

const { data, query, search, reset, loading } = useGetList(api)

const types = ref<any[]>([])

onMounted(async () => {
    search()
    const res: any = await http.get('telegram/ledger/types')
    types.value = res?.data ?? []
})

const typeText = (t: string) => types.value.find(i => i.value === t)?.label ?? t
</script>
