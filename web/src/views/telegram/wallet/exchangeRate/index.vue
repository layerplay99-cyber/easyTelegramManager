<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="源币种" prop="from_currency">
                    <el-input v-model="query.from_currency" name="from_currency" clearable />
                </el-form-item>
                <el-form-item label="目标币种" prop="to_currency">
                    <el-input v-model="query.to_currency" name="to_currency" clearable />
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <Operate :show="open" />
            <el-table :data="(data as any)?.data || []" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column prop="from_currency" label="源币种" width="100" />
                <el-table-column prop="to_currency" label="目标币种" width="100" />
                <el-table-column prop="rate" label="基准汇率" />
                <el-table-column prop="buy_rate" label="买入价" />
                <el-table-column prop="sell_rate" label="卖出价" />
                <el-table-column prop="source" label="来源" width="120" />
                <el-table-column label="自动更新" width="100">
                    <template #default="scope">
                        <el-tag :type="scope.row.auto_update ? 'success' : 'info'" size="small">
                            {{ scope.row.auto_update ? '是' : '否' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="状态" width="90">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 1 ? 'success' : 'info'">
                            {{ scope.row.status === 1 ? '启用' : '停用' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="180">
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
