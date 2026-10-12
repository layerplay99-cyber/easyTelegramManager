<template>
    <div>
        <el-tabs v-model="tab">
            <el-tab-pane :label="$t('wallet.riskRules')" name="rules">
                <Search :search="searchRules" :reset="resetRules">
                    <template v-slot:body>
                        <el-form-item :label="$t('wallet.ruleName')" prop="name">
                            <el-input v-model="ruleQuery.name" name="name" clearable />
                        </el-form-item>
                        <el-form-item :label="$t('wallet.action')" prop="action">
                            <el-select v-model="ruleQuery.action" :placeholder="$t('system.pleaseSelect')" clearable>
                                <el-option v-for="item in actionOptions" :key="item.value" :label="item.label" :value="item.value" />
                            </el-select>
                        </el-form-item>
                    </template>
                </Search>
                <div class="table-default">
                    <Operate :show="open" />
                    <el-table :data="(ruleData as any)?.data || []" class="mt-3" v-loading="ruleLoading">
                        <el-table-column prop="id" label="ID" width="80" />
                        <el-table-column prop="name" :label="$t('wallet.ruleName')" />
                        <el-table-column prop="type" :label="$t('wallet.scene')" width="100" />
                        <el-table-column :label="$t('wallet.action')" width="120">
                            <template #default="scope">{{ actionText(scope.row.action) }}</template>
                        </el-table-column>
                        <el-table-column prop="risk_level" :label="$t('wallet.riskLevel')" width="100" />
                        <el-table-column prop="priority" :label="$t('wallet.priority')" width="90" />
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
                                <el-button size="small" @click="toggleRule(scope.row)">
                                    {{ scope.row.status === 1 ? $t('system.disabled') : $t('system.enabled') }}
                                </el-button>
                                <Destroy @click="destroy(rulesApi, scope.row.id)" />
                            </template>
                        </el-table-column>
                    </el-table>
                    <Paginate />
                </div>
            </el-tab-pane>

            <el-tab-pane :label="$t('wallet.riskLogs')" name="logs">
                <Search :search="searchLogs" :reset="resetLogs">
                    <template v-slot:body>
                        <el-form-item :label="$t('wallet.memberId')" prop="member_id">
                            <el-input v-model="logQuery.member_id" name="member_id" clearable />
                        </el-form-item>
                        <el-form-item :label="$t('system.status')" prop="status">
                            <el-select v-model="logQuery.status" :placeholder="$t('system.pleaseSelect')" clearable>
                                <el-option v-for="item in logStatusOptions" :key="item.value" :label="item.label" :value="item.value" />
                            </el-select>
                        </el-form-item>
                    </template>
                </Search>
                <div class="table-default">
                    <el-table :data="(logData as any)?.data || []" class="mt-3" v-loading="logLoading">
                        <el-table-column prop="id" label="ID" width="80" />
                        <el-table-column prop="member_id" :label="$t('wallet.memberId')" width="100" />
                        <el-table-column prop="rule_id" :label="$t('wallet.ruleId')" width="90" />
                        <el-table-column :label="$t('wallet.triggerData')">
                            <template #default="scope">
                                <span class="text-xs text-gray-500">{{ jsonText(scope.row.trigger_data) }}</span>
                            </template>
                        </el-table-column>
                        <el-table-column :label="$t('wallet.action')" width="120">
                            <template #default="scope">{{ actionText(scope.row.action) }}</template>
                        </el-table-column>
                        <el-table-column :label="$t('system.status')" width="100">
                            <template #default="scope">
                                <el-tag :type="scope.row.status === 1 ? 'success' : 'warning'" size="small">
                                    {{ logStatusText(scope.row.status) }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column prop="created_at" :label="$t('wallet.time')" />
                        <el-table-column :label="$t('system.operation')" width="120">
                            <template #default="scope">
                                <el-button v-if="scope.row.status !== 1" size="small" type="success" @click="handleLog(scope.row.id)">
                                    {{ $t('wallet.markHandled') }}
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
import { t } from '@/support/helper'

const rulesApi = 'telegram/risk-control/rules'
const logsApi = 'telegram/risk-control/logs'

const tab = ref('rules')

const { data: ruleData, query: ruleQuery, search: searchRules, reset: resetRules, loading: ruleLoading } = useGetList(rulesApi)
const { data: logData, query: logQuery, search: searchLogs, reset: resetLogs, loading: logLoading } = useGetList(logsApi)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const actionOptions = [
    { value: 'reject', label: t('wallet.actionReject') },
    { value: 'manual_audit', label: t('wallet.actionManualAudit') },
    { value: 'freeze', label: t('wallet.actionFreeze') },
]
const logStatusOptions = [
    { value: 0, label: t('wallet.pending') },
    { value: 1, label: t('wallet.handled') },
]

const actionText = (a: string) => actionOptions.find(i => i.value === a)?.label ?? a
const logStatusText = (s: number) => logStatusOptions.find(i => i.value === s)?.label ?? String(s)
const jsonText = (v: any) => (typeof v === 'string' ? v : JSON.stringify(v ?? {}))

const toggleRule = async (row: any) => {
    await http.post(`telegram/risk-control/rules/${row.id}/toggle-status`)
    ElMessage.success(t('wallet.operateSuccess'))
    resetRules()
}

const handleLog = async (id: number) => {
    await ElMessageBox.confirm(t('wallet.confirmHandled'), t('system.warning'), { type: 'warning' })
    await http.post(`telegram/risk-control/logs/${id}/handle`)
    ElMessage.success(t('wallet.operateSuccess'))
    resetLogs()
}

searchRules()
searchLogs()
deleted(resetRules)
</script>
