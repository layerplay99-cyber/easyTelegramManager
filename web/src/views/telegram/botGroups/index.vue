<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item label="群名称" prop="name">
                    <el-input v-model="query.name" name="name" clearable />
                </el-form-item>
                <!-- <el-form-item label="状态" prop="enabled">
                    <el-select v-model="query.enabled" placeholder="请选择" clearable>
                        <el-option v-for="item in options" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item> -->
            </template>
        </Search>
        <div class="table-default">
            <div class="pt-5 pl-2">
                <el-button type="primary" plain @click="handleExportTemplate">
                    + 批量设置分组
                </el-button>
            </div>

            <!-- <Operate :show="open" /> -->
            <el-table :data="tableData" class="mt-3" v-loading="loading" ref="tableRef">
                <el-table-column type="selection" width="55" />
                <el-table-column prop="id" label="id" width="100" />
                <el-table-column prop="bot_id" label="botId" />
                <el-table-column prop="app_id" label="appId" />
                <el-table-column prop="name" label="群名称" />
                <el-table-column prop="chat_id" label="群聊ID" />
                <el-table-column prop="group_group.name" label="分组名称" />
                <el-table-column prop="enabled" label="状态">
                    <template #default="scope">
                        <label v-if="scope.row.enabled === 1">启用</label>
                        <label v-else>禁用</label>
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" label="创建时间" />
                <el-table-column prop="updated_at" label="更新时间" />
                <el-table-column label="操作" width="350">
                    <template #default="scope">
                        <el-button type="success" size="small" @click="openConfigDialog(scope.row)">
                            <Icon name="cog" className="w-4 h-4 mr-1" /> 配置
                        </el-button>
                        <el-button type="primary" size="small" @click="openFeatureDialog(scope.row)">
                            <Icon name="puzzle-piece" className="w-4 h-4 mr-1" /> 功能
                        </el-button>
                        <!-- <Update @click="open(scope.row.id)" /> -->
                        <Destroy @click="destroy(api, scope.row.id)" />
                    </template>
                </el-table-column>
            </el-table>
            <Paginate />
        </div>

        <Dialog v-model="visible" :title="title" destroy-on-close>
            <Create @close="close(reset)" :primary="id" :api="api" />
        </Dialog>

        <Dialog v-model="group_visible" :title="group_title" destroy-on-close>
            <el-form label-width="120px" class="pr-4">
                <el-form-item label="选择群分组" prop="group_id">
                    <el-select v-model="groupForm.group_id" filterable placeholder="请选择群聊"
                        style="width: 100%;">
                        <el-option v-for="item in groupList" :key="item.id" :label="item.name + ' (' + item.id + ')'"
                            :value="item.id" />
                    </el-select>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="handleGroupSubmit">提交</el-button>
                    <el-button @click="group_visible = false">取消</el-button>
                </el-form-item>
            </el-form>
        </Dialog>

        <!-- 功能配置对话框 -->
        <FeatureConfig
            v-model="featureDialogVisible"
            :target-id="featureTargetId"
            :bot-id="featureBotId"
            :initial-features="currentGroupFeatures"
            category="bot"
            @saved="handleFeatureSaved"
        />

        <!-- 配置对话框 -->
        <el-dialog v-model="configDialog.visible" title="群组配置" width="600px" destroy-on-close>
            <el-form :model="configDialog.form" label-width="120px">
                <el-form-item label="商户ID" prop="mid">
                    <el-input v-model="configDialog.form.mid" placeholder="请输入商户ID" />
                </el-form-item>

                <el-form-item label="客服" prop="customers">
                    <el-input
                        v-model="configDialog.form.customers"
                        placeholder="多个客服用逗号分隔"
                        type="textarea"
                        :rows="2"
                    />
                    <div class="text-gray-500 text-xs mt-1">提示：多个客服请用逗号分隔</div>
                </el-form-item>

                <el-form-item label="回复语言" prop="replyLang">
                    <el-select v-model="configDialog.form.replyLang" placeholder="请选择回复语言" style="width: 100%">
                        <el-option label="中文" value="zh_CN" />
                        <el-option label="英文" value="en_US" />
                        <el-option label="泰语" value="th_TH" />
                        <el-option label="越南语" value="vi_VN" />
                    </el-select>
                </el-form-item>

                <el-form-item label="欢迎语" prop="welcome">
                    <el-input
                        v-model="configDialog.form.welcome"
                        placeholder="请输入欢迎语"
                        type="textarea"
                        :rows="3"
                    />
                </el-form-item>

                <el-form-item label="自动回复" prop="autoReply">
                    <div class="space-y-2">
                        <div v-for="(item, index) in configDialog.form.autoReply" :key="index" class="flex gap-2">
                            <el-input
                                v-model="item.key"
                                placeholder="关键词"
                                style="flex: 1"
                            />
                            <el-input
                                v-model="item.value"
                                placeholder="回复内容"
                                style="flex: 2"
                            />
                            <el-button
                                type="danger"
                                link
                                @click="removeAutoReply(index)"
                            >
                                删除
                            </el-button>
                        </div>
                        <el-button type="primary" link @click="addAutoReply">
                            + 添加自动回复
                        </el-button>
                    </div>
                </el-form-item>
            </el-form>

            <template #footer>
                <div class="dialog-footer">
                    <el-button @click="configDialog.visible = false">取消</el-button>
                    <el-button type="primary" @click="saveConfig" :loading="configDialog.loading">
                        保存配置
                    </el-button>
                </div>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref, watch } from 'vue'
import Create from './create.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import { useGroup } from '@/stores/modules/telegram/group'
import FeatureConfig from '@/components/telegram/FeatureConfig.vue'

const api = 'telegram/bot/groups'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const tableRef = ref()
const group_visible = ref(false);
const group_title = ref("批量设置分组");
const tableData = computed(() => data.value?.data)
// 状态选项定义
const options = ref([
    { value: 1, label: '启用' },
    { value: 0, label: '禁用' }
])

const groupList = ref<Array<{ id: number; name: string }>>([]);

// 功能配置对话框相关
const featureDialogVisible = ref(false)
const featureTargetId = ref<number | null>(null)
const featureBotId = ref<number | null>(null)
const currentGroupFeatures = ref<any[]>([])

const openFeatureDialog = (row: any) => {
    featureTargetId.value = parseInt(row.chat_id)
    featureBotId.value = null
    currentGroupFeatures.value = row.features_binds || []
    featureDialogVisible.value = true
}

const handleFeatureSaved = () => {
    search() // 刷新列表
}

// 类型映射（保留用于其他地方可能使用）
const categoryMap: Record<string, string> = {
    'system': '系统',
    'custom': '客户端',
    'bot': '机器人',
    'realMan': '真人'
};

// 配置对话框相关
const configDialog = ref({
    visible: false,
    loading: false,
    groupId: null as number | null,
    configId: null as number | null, // 存储已有配置的ID
    form: {
        mid: '',
        customers: '',
        welcome: '',
        replyLang: 'zh_CN',
        autoReply: [] as Array<{ key: string; value: string }>
    }
});
const getGroupList = async () => {
    try {
        const response = await useGroup().getGroupGroupList();
        // 列表接口返回的是分页结构：{ status, data: { data: [...], total, ... } }。
        // 之前读的是 response.total（实际在 response.data.total，恒为 undefined），
        // 导致 if 判断失败、不赋值 groupList，下拉为空。兼容数组/分页两种返回。
        groupList.value = response?.data?.data || response?.data || [];
    } catch (error) {
        console.error('获取分组列表异常:', error);
    }
};

//批量获取勾选的群组，并设置分组
const groupForm = ref<{ group_id: number }>({ group_id: 0 });

const handleGroupSubmit = () => {
    console.log('选中的分组ID:', groupForm.value.group_id)

    // 获取勾选的表格行
    const selectedRows = tableRef.value?.getSelectionRows() || []
    console.log('选中的行:', selectedRows)

    if (selectedRows.length === 0) {
        console.warn('请先选择要设置的群组')
        return
    }

    if (!groupForm.value.group_id) {
        console.warn('请选择分组')
        return
    }

    useGroup().updateGroup(0, {
        group_id: selectedRows.map(item => item.id),
        group_group_ids: groupForm.value.group_id
    }).then(() => {
        console.log('设置分组成功')
        group_visible.value = false;
        search(); // 刷新列表
    }).catch((error) => {
        console.error('设置分组失败:', error);
    });
}
const handleExportTemplate = () => {
    group_visible.value = true;
};

// 打开配置对话框
const openConfigDialog = async (row: any) => {
    configDialog.value.groupId = row.id
    configDialog.value.loading = true
    configDialog.value.visible = true

    try {
        // 获取配置数据
        const response = await useGroup().getGroupConfig(row.id)

        if (response.data) {
            const config = response.data
            configDialog.value.configId = config.id || null
            configDialog.value.form = {
                mid: config.mid || '',
                customers: config.customers || '',
                welcome: config.welcome || '',
                replyLang: config.replyLang || 'zh_CN',
                autoReply: config.autoReply ?
                    Object.entries(config.autoReply).map(([key, value]) => ({ key, value: value as string })) :
                    []
            }
        } else {
            // 如果没有配置数据，使用默认值
            configDialog.value.configId = null
            configDialog.value.form = {
                mid: '',
                customers: '',
                welcome: '',
                replyLang: 'zh_CN',
                autoReply: []
            }
        }
    } catch (error) {
        console.error('获取配置失败:', error)
        // 出错时也使用默认值
        configDialog.value.configId = null
        configDialog.value.form = {
            mid: '',
            customers: '',
            welcome: '',
            replyLang: 'zh_CN',
            autoReply: []
        }
    } finally {
        configDialog.value.loading = false
    }
}// 添加自动回复项
const addAutoReply = () => {
    configDialog.value.form.autoReply.push({ key: '', value: '' })
}

// 删除自动回复项
const removeAutoReply = (index: number) => {
    configDialog.value.form.autoReply.splice(index, 1)
}

// 保存配置
const saveConfig = async () => {
    if (!configDialog.value.groupId) {
        console.error('群组ID不存在')
        return
    }

    configDialog.value.loading = true

    try {
        // 将autoReply数组转换为对象格式
        const autoReplyObj = configDialog.value.form.autoReply.reduce((acc, item) => {
            if (item.key && item.value) {
                acc[item.key] = item.value
            }
            return acc
        }, {} as Record<string, string>)

        const configData = {
            group_id: configDialog.value.groupId,
            mid: configDialog.value.form.mid,
            customers: configDialog.value.form.customers,
            welcome: configDialog.value.form.welcome,
            replyLang: configDialog.value.form.replyLang,
            autoReply: autoReplyObj
        }

        // 如果configId为空，传入0，否则传入configId
        const saveId = configDialog.value.configId || 0
        const response = await useGroup().createOrUpdateGroupConfig(saveId, configData as any)

        if (response.success) {
            console.log('保存配置成功')
            configDialog.value.visible = false
            search() // 刷新列表
        } else {
            console.error('保存配置失败:', response.message)
        }
    } catch (error) {
        console.error('保存配置失败:', error)
    } finally {
        configDialog.value.loading = false
    }
}

onMounted(() => {
    search()
    deleted(reset)
    getGroupList()
})
</script>
