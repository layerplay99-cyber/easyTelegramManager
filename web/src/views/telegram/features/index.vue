<template>
    <div>
        <Search :search="search" :reset="reset">
            <template v-slot:body>
                <el-form-item :label="$t('feature.field.name')" prop="name">
                    <el-input v-model="(query as any).name" name="name" clearable />
                </el-form-item>
                <el-form-item :label="$t('feature.field.enabled')" prop="enabled">
                    <el-select v-model="(query as any).enabled" :placeholder="$t('system.pleaseSelect')" clearable
                        multiple>
                        <el-option v-for="item in enabled" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item>
            </template>
        </Search>
        <div class="table-default">
            <Operate :show="open" />
            <el-table :data="tableData" class="mt-3" v-loading="loading">
                <el-table-column prop="name" :label="$t('feature.field.name')" />
                  <el-table-column prop="category" :label="$t('feature.field.category')" />
                      <!-- 执行器：取代旧的「功能类型/请求类型/数据来源」三个枚举 -->
                            <el-table-column prop="driver_label" :label="$t('feature.driverLabel')" width="180">
                                <template #default="scope">
                            <el-tag size="small">{{ scope.row.driver_label || scope.row.driver }}</el-tag>
                                </template>
                              </el-table-column>
                              <el-table-column :label="$t('feature.triggerLabel')" width="110">
                                <template #default="scope">
                            {{ triggerLabel(scope.row.trigger) }}
                          </template>
                              </el-table-column>
                <el-table-column prop="description" :label="$t('feature.field.description')" width="200">
                    <template #default="scope">
                        <el-tooltip
                            :content="scope.row.description || ''"
                            placement="top"
                            :disabled="!scope.row.description"
                            effect="dark"
                        >
                            <span class="description-text">{{ scope.row.description || '' }}</span>
                        </el-tooltip>
                    </template>
                </el-table-column>
                <el-table-column :label="$t('feature.commandsLabel')" min-width="150">
                    <template #default="scope">
                    <template v-if="commandsOf(scope.row.id).length">
                        <el-tag
                   v-for="c in commandsOf(scope.row.id)"
                    :key="c.id"
                             size="small"
                        class="mr-1"
                       >
                      /{{ c.command }}
                          </el-tag>
                       </template>
                   <span v-else class="text-xs text-gray-400">-</span>
                       </template>
                          </el-table-column>
                <el-table-column :label="$t('feature.field.enabled')" width="100">
                    <template #default="scope">
                        <el-switch
                            v-model="scope.row.enabled"
                            :active-value="1"
                            :inactive-value="0"
                            @change="() => handleEnabledChange(scope.row)"
                            :loading="scope.row.switchLoading"
                        />
                    </template>
                </el-table-column>
                <el-table-column prop="created_at" :label="$t('system.createTime')" />
                <el-table-column prop="updated_at" :label="$t('system.updateTime')" />
                <el-table-column :label="$t('system.operation')" width="260">
                    <template #default="scope">
                        <Update @click="open(scope.row.id)" />
                        <!-- 只有「接收三方推送」这类功能才有回调入口 -->
                        <el-button
                            v-if="scope.row.driver === 'hook.receive'"
                            type="primary"
                            link
                            size="small"
                            @click="openHook(scope.row.id)"
                        >
                            {{ $t('feature.hook.title') }}
                        </el-button>
                        <Destroy @click="destroy(api, scope.row.id)" />
                    </template>
                </el-table-column>
            </el-table>
            <Paginate />
        </div>

        <!-- key 绑定 id + v-if 绑定 visible：
         Create 内部的 useShow 只在 setup 执行一次。若不随 visible 挂载/卸载，
         或 id 变化时组件被复用，第二次点开其它功能仍会显示第一次那条记录。
         destroy-on-close 只清 el-dialog 内部内容，vnode 仍可能被 patch 复用，
         所以这里必须再用 v-if 强制销毁。 -->
        <Dialog v-model="visible" :title="title" destroy-on-close>
            <Create v-if="visible" :key="id" @close="close(search)" :primary="id" :api="api" />
        </Dialog>

        <!-- 回调设置：同样用 v-if + key 强制销毁，避免复用上一次的记录 -->
        <Dialog v-model="hookVisible" :title="$t('feature.hook.title')" width="900px" destroy-on-close>
            <Hook v-if="hookVisible" :key="hookFeatureId" :primary="hookFeatureId" :api="api" />
        </Dialog>
    </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Create from './create.vue'
import Hook from './hook.vue'
import { useGetList } from '@/composables/curd/useGetList'
import { useDestroy } from '@/composables/curd/useDestroy'
import { useOpen } from '@/composables/curd/useOpen'
import { useUserStore } from '@/stores/modules/user'
import http from '@/support/http'
import Message from '@/support/message'

const api = 'telegram/features'

const { data, query, search, reset, loading } = useGetList(api)
const { destroy, deleted } = useDestroy()
const { open, close, title, visible, id } = useOpen()

const userStore = useUserStore()
const isSuperAdmin = userStore.isSuperAdmin()

// 触发方式国际化（默认中文，取不到时回落原始 key）
const { t } = useI18n()

const enabled = [
    { label: t('system.enabled'), value: 1 },
    { label: t('system.disabled'), value: 0 }
]

// 归属映射（列表已改用 driver/trigger，旧的 type/requestType/location 列已移除）
const categoryMap = {
    'system': t('feature.categories.system'),
    'custom': t('feature.categories.custom'),
  'bot': t('feature.categories.bot'),
    'realMan': t('feature.categories.realMan')
}

const enabledMap = {
    1: t('system.enabled'),
    0: t('system.disabled')
}

// 转换表格数据，将 value 转换为 label 显示
const tableData = computed(() => {
    if (!(data.value as any)?.data) return []

    return (data.value as any).data.map((item: any) => ({
            ...item,
            // 标准化 enabled 为数字 1/0：后端可能返回 undefined / "1" / "0" / 布尔，
            // 若与 el-switch 的 active-value(1)/inactive-value(0) 不匹配，
            // 组件初始化时会把值纠正并触发一次 change，导致列表加载时
            // 自动对每条记录发一次 PUT telegram/features/{id}。
            enabled: item.enabled == 1 ? 1 : 0,
            category: categoryMap[item.category as keyof typeof categoryMap] || item.category,
            switchLoading: false // 添加开关加载状态
        }))
})

// 处理启用状态变化
const handleEnabledChange = async (row: any) => {
    row.switchLoading = true
    try {
        // 直接调用更新API，只传递id和enabled状态
        await http.put(`${api}/${row.id}`, { enabled: row.enabled })
        Message.success(t('feature.statusUpdated'))
        // 更新成功后刷新数据
        search()
    } catch (error) {
        // 如果更新失败，恢复原状态
        row.enabled = row.enabled === 1 ? 0 : 1
        Message.error(t('system.updateFailed'))
    } finally {
        row.switchLoading = false
    }
}

// 回调设置弹窗
const hookVisible = ref(false)
const hookFeatureId = ref<number | string>('')

const openHook = (featureId: number | string) => {
  hookFeatureId.value = featureId
  hookVisible.value = true
}

const triggerLabel = (key: string) => {
  if (!key) return '-'
  const i18nKey = `feature.triggers.${key}`
  const text = t(i18nKey)
  return text === i18nKey ? key : text
}

// 各功能的命令列表：一次批量请求拿全部，避免 N+1
const commandMap = ref<Record<string, any[]>>({})
const commandsOf = (featureId: number) => commandMap.value[String(featureId)] || []

// 注意：这里必须是普通 boolean，不能用 .value 访问（那永远是 undefined，去重会失效）
let commandsLoaded = false

const loadAllCommands = async () => {
  if (commandsLoaded) return   // 只加载一次，防止重复请求
  commandsLoaded = true

  try {
    const { data } = await http.get('telegram/features/commands')
    // 批量接口返回「功能ID => 命令数组」映射
    const map = data.data || {}
    const normalized: Record<string, any[]> = {}
    Object.keys(map).forEach((k) => {
      normalized[String(k)] = Array.isArray(map[k]) ? map[k] : []
    })
    commandMap.value = normalized
  } catch {
    commandMap.value = {}
  }
}

// 列表数据到位后加载命令（只触发一次，不做 deep 监听）
watch(
  () => (data.value as any)?.data?.length,
  (len) => {
    if (len > 0) loadAllCommands()
  },
  { immediate: true }
)

onMounted(() => {
  search()
  deleted(reset)
})
</script>

<style scoped>
.description-text {
    display: inline-block;
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: top;
}
</style>
