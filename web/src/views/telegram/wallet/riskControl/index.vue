<template>
    <div>
        <el-tabs v-model="tab">
            <el-tab-pane label="风控规则" name="rules">
                <Search :search="searchRules" :reset="resetRules">
                    <template v-slot:body>
                        <el-form-item label="规则名称" prop="name">
                            <el-input v-model="ruleQuery.name" name="name" clearable />
                        </el-form-item>
                        <el-form-item label="动作" prop="action">
                            <el-select v-model="ruleQuery.action" placeholder="请选择" clearable>
                                <el-option v-for="item in actionOptions" :key="item.value" :label="item.label" :value="item.value" />
                            </el-select>
                        </el-form-item>
                    </template>
                </Search>
                <div class="table-default">
                    <Operate :show="open" />
                    <el-table :data="(ruleData as any)?.data || []" class="mt-3" v-loading="ruleLoading">
                        <el-table-column prop="id" label="ID" width="80" />
                        <el-table-column prop="name" label="规则名称" />
                        <el-table-column prop="type" label="场景" width="100" />
                        <el-table-column label="动作" width="120">
                            <template #default="scope">{{ actionText(scope.row.action) }}</template>
                        </el-table-column>
                        <el-table-column prop="risk_level" label="风险等级" width="100" />
                        <el-table-column prop="priority" label="优先级" width="90" />
                        <el-table-column label="状态" width="90">
                            <template #default="scope">
                                <el-tag :type="scope.row.status === 1 ? 'success' : 'info'">
                                    {{ scope.row.status === 1 ? '启用' : '停用' }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column label="操作" width="200">
                            <template #default="scope">
                                <Update @click="open(scope.row.id)" />
                                <el-button size="small" @click="toggleRule(scope.row)">
                                    {{ scope.row.status === 1 ? '停用' : '启用' }}
                                </el-button>
                                <Destroy @click="destroy(rulesApi, scope.row.id)" />
                            </template>
                        </el-table-column>
                    </el-table>
                    <Paginate />
                </div>
            </el-tab-pane>

            <el-tab-pane label="风控日志" name="logs">
                <Search :search="searchLogs" :reset="resetLogs">
                    <template v-slot:body>
                        <el-form-item label="会员ID" prop="member_id">
                            <el-input v-model="logQuery.member_id" name="member_id" clearable />
                        </el-form-item>
                        <el-form-item label="状态" prop="status">
                            <el-select v-model="logQuery.status" placeholder="请选择" clearable>
                                <el-option v-for="item in logStatusOptions" :key="item.value" :label="item.label" :value="item.value" />
                            </el-select>
                        </el-form-item>
                    </template>
                </Search>
                <div class="table-default">
                    <el-table :data="(logData as any)?.data || []" class="mt-3" v-loading="logLoading">
                        <el-table-column prop="id" label="ID" width="80" />
                        <el-table-column prop="member_id" label="会员ID" width="100" />
                        <el-table-column prop="rule_id" label="规则ID" width="90" />
                        <el-table-column label="触发数据">
                            <template #default="scope">
                                <span class="text-xs text-gray-500">{{ jsonText(scope.row.trigger_data) }}</span>
                            </template>
                        </el-table-column>
                        <el-table-column label="动作" width="120">
                            <template #default="scope">{{ actionText(scope.row.action) }}</template>
                        </el-table-column>
                        <el-table-column label="状态" width="100">
                            <template #default="scope">
                                <el-tag :type="scope.row.status === 1 ? 'success' : 'warning'" size="small">
                                    {{ logStatusText(scope.row.status) }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column prop="created_at" label="时间" />
                        <el-table-column label="操作" width="120">
                            <template #default="scope">
                                <el-button v-if="scope.row.status !== 1" size="small" type="success" @click="handleLog(scope.row.id)">
                                    标记处理
                                </el-button>
                            </template>
                        </el-table-column>
                    </el-table>
                    <Paginate />
                </div>
            </el-tab-pane>
        </el-tabs>

        <Dialog v-model="visible" :title="title" destroy-on-close>
            <Create @close="close(resetRules)" :primary="id" :api="rulesApi" />
        </Dialog>
    </div>
</template>

<script lang="ts" setup>
import { ref } from 'vue'
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import http from '@/support/http'
import { ElMessage, ElMessageBox } from 'element-plus'

const rulesApi = 'telegram/risk-control/rules'
const logsApi = 'telegram/risk-control/logs'

const tab = ref('rules')

const { data: ruleData, query: ruleQuery, search: searchRules, reset: resetRules, loading: ruleLoading } = useGetList(rulesApi)
const { data: logData, query: logQuery, search: searchLogs, reset: resetLogs, loading: logLoading } = useGetList(logsApi)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const actionOptions = [
    { value: 'reject', label: '拒绝' },
    { value: 'manual_audit', label: '人工审核' },
    { value: 'freeze', label: '冻结' },
]
const logStatusOptions = [
    { value: 0, label: '待处理' },
    { value: 1, label: '已处理' },
]

const actionText = (a: string) => actionOptions.find(i => i.value === a)?.label ?? a
const logStatusText = (s: number) => logStatusOptions.find(i => i.value === s)?.label ?? String(s)
const jsonText = (v: any) => (typeof v === 'string' ? v : JSON.stringify(v ?? {}))

const toggleRule = async (row: any) => {
    await http.post(`telegram/risk-control/rules/${row.id}/toggle-status`)
    ElMessage.success('操作成功')
    resetRules()
}

const handleLog = async (id: number) => {
    await ElMessageBox.confirm('确认标记为已处理？', '提示', { type: 'warning' })
    await http.post(`telegram/risk-control/logs/${id}/handle`)
    ElMessage.success('操作成功')
    resetLogs()
}

searchRules()
searchLogs()
deleted(resetRules)
</script>
