<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="Bot-ID" prop="name">
                    <el-input v-model="query.name" name="name" clearable />
                </el-form-item>
                <el-form-item label="Bot用户名" prop="username">
                    <el-input v-model="query.username" name="username" clearable />
                </el-form-item>
                <el-form-item label="状态" prop="enabled">
                    <el-select v-model="query.enabled" placeholder="请选择" clearable>
                        <el-option v-for="item in enabled" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <!-- <div class="pt-5 pl-2">
                <el-button type="primary" plain @click="openBroadcastDialog">
                    <Icon name="paper-plane" className="w-4 h-4 mr-1" /> 消息群发
                </el-button>
            </div> -->
            <Operate :show="open" />
            <el-table :data="tableData" class="mt-3" v-loading="loading">
                <el-table-column prop="id" label="id" />
                <el-table-column prop="api_token" label="Api_Token" />
                <el-table-column prop="username" label="Bot用户名" />
                <el-table-column prop="url_token" label="Url-Token" />
                <el-table-column prop="description" label="Bot描述" />
                <el-table-column prop="enabled" label="激活状态">
                    <template #default="scope">
                        <el-switch
                            v-model="scope.row.enabled"
                            :active-value="1"
                            :inactive-value="0"
                            @change="handleEnabledChange(scope.row)"
                            :loading="scope.row.switchLoading"
                        />
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" />
                <el-table-column prop="updated_at" label="更新时间" />
                <el-table-column label="操作" width="380">
                    <template #default="scope">
                        <el-button type="success" size="small" @click="openBotBroadcastDialog(scope.row)">
                            <Icon name="paper-plane" className="w-4 h-4 mr-1" /> 群发
                        </el-button>
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

        <!-- 消息群发对话框 -->
        <BroadcastDialog
            v-model="broadcastDialog.visible"
            :bot-id="broadcastDialog.botId"
            @success="handleBroadcastSuccess"
        />
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue'
import Create from './create.vue'
import BroadcastDialog from './BroadcastDialog.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import { useTelegramStore } from '@/stores/modules/telegram'
import { useBotStore } from '@/stores/modules/telegram/botApi'
import Icon from '@/components/icon/index.vue'
import Message from '@/support/message'

const api = 'telegram/bots'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()
const telegramStore = useTelegramStore()

// 状态选项定义
const enabled = ref([
    { value: 1, label: '已激活' },
    { value: 0, label: '待激活' }
])

// 处理启用状态变化
const handleEnabledChange = async (row: any) => {
    row.switchLoading = true
    try {
        // 如果是激活（enabled从0变1），调用setBotWebHook
        // 如果是关闭（enabled从1变0），调用delBotWebHook
        if (row.enabled === 1) {
            await telegramStore.setBotWebHook(row.id)
            Message.success('机器人激活成功')
        } else {
            await telegramStore.delBotWebHook(row.id)
            Message.success('机器人已关闭')
        }
        search()
    } catch (error) {
        row.enabled = row.enabled === 1 ? 0 : 1
        Message.error('操作失败')
    } finally {
        row.switchLoading = false
    }
}

const tableData = computed(() => {
    return data.value && 'data' in data.value ? (data.value as any).data : []
})

// 消息群发对话框相关
const broadcastDialog = ref({
    visible: false,
    botId: null as number | null
})

// 打开全局消息群发对话框
const openBroadcastDialog = () => {
    broadcastDialog.value.botId = null
    broadcastDialog.value.visible = true
}

// 打开单个bot的消息群发对话框
const openBotBroadcastDialog = (row: any) => {
    broadcastDialog.value.botId = row.id
    broadcastDialog.value.visible = true
}

// 消息群发成功回调
const handleBroadcastSuccess = () => {
    // 不需要在这里显示提示，子组件已经处理了
}

onMounted(() => {
    search()
    deleted(reset)
})
</script>
