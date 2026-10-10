<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="会员ID" prop="member_id">
                    <el-input v-model="query.member_id" name="member_id" clearable />
                </el-form-item>
                <el-form-item label="币种" prop="currency">
                    <el-select v-model="query.currency" placeholder="请选择" clearable>
                        <el-option v-for="c in currencies" :key="c" :label="c" :value="c" />
                    </el-select>
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <el-table :data="(data as any)?.data || []" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column prop="member_id" label="会员ID" />
                <el-table-column prop="currency" label="币种" width="90" />
                <el-table-column prop="balance" label="可用余额" />
                <el-table-column prop="frozen_balance" label="冻结余额" />
                <el-table-column prop="total_recharge" label="累计充值" />
                <el-table-column prop="total_withdraw" label="累计提现" />
                <el-table-column label="状态" width="90">
                    <template #default="scope">
                        <el-tag :type="scope.row.status === 1 ? 'success' : 'info'">
                            {{ scope.row.status === 1 ? '正常' : '禁用' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="200">
                    <template #default="scope">
                        <el-button type="warning" size="small" @click="openAdjust(scope.row)">调整余额</el-button>
                        <el-button size="small" @click="toggleStatus(scope.row)">
                            {{ scope.row.status === 1 ? '禁用' : '启用' }}
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
            <Paginate />
        </div>

        <el-dialog v-model="adjustVisible" :title="`调整余额（钱包 #${current?.id}）`" width="480px">
            <el-form :model="adjustForm" label-width="100px">
                <el-form-item label="类型">
                    <el-radio-group v-model="adjustForm.type">
                        <el-radio-button label="reward">奖励（加钱）</el-radio-button>
                        <el-radio-button label="deduct">扣款（减钱）</el-radio-button>
                    </el-radio-group>
                </el-form-item>
                <el-form-item label="金额">
                    <el-input-number v-model="adjustForm.amount" :min="0.01" :precision="2" />
                </el-form-item>
                <el-form-item label="事由">
                    <el-input v-model="adjustForm.title" placeholder="必填，会写入操作日志" />
                </el-form-item>
                <el-form-item label="备注">
                    <el-input v-model="adjustForm.description" type="textarea" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="adjustVisible = false">取消</el-button>
                <el-button type="primary" :loading="submitting" @click="submitAdjust">确定</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup>
import { onMounted, ref } from 'vue'
import { useGetList } from '@/composables/curd/useGetList'
import http from '@/support/http'
import { ElMessage } from 'element-plus'

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
        ElMessage.warning('请填写调整事由')
        return
    }

    submitting.value = true
    try {
        await http.post(`telegram/wallet/${current.value.id}/adjust-balance`, adjustForm.value)
        ElMessage.success('余额调整成功')
        adjustVisible.value = false
        reset()
    } finally {
        submitting.value = false
    }
}

const toggleStatus = async (row: any) => {
    await http.post(`telegram/wallet/${row.id}/toggle-status`)
    ElMessage.success('操作成功')
    reset()
}

// useGetList 不会自动发首次请求（loading 初始为 true），
// 必须在这里触发，否则列表一直转圈且 Network 里没有任何请求。
onMounted(() => {
    search()
})
</script>
