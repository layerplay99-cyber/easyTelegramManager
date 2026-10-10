<template>
  <div>
    <!-- 顶部：选择要配置的上游 -->
    <div class="table-default">
      <el-card shadow="never">
        <template #header>
          <div class="flex items-center justify-between">
            <span class="font-medium">上游接口路径配置</span>
            <el-button type="primary" :loading="saving" :disabled="!configId" @click="handleSave">
              保存
            </el-button>
          </div>
        </template>

        <el-alert type="info" show-icon :closable="false" class="mb-4">
          <template #title>
            同一个功能在不同上游的路径可能不同。这里为每个「上游 + 接口」单独配置路径；
            <b>留空表示跟随平台默认路径</b>。若路径以 http(s):// 开头则视为绝对地址，忽略上游 base_url。
          </template>
        </el-alert>

        <el-form-item label="上游配置">
          <el-select
            v-model="configId"
            placeholder="请选择上游"
            filterable
            class="w-80"
            @change="loadEndpoints"
          >
            <el-option v-for="c in configs" :key="c.id" :label="c.name" :value="c.id" />
          </el-select>
          <span v-if="currentConfig?.api_url" class="ml-3 text-gray-500 text-sm">
            base_url：{{ currentConfig.api_url }}
          </span>
        </el-form-item>
      </el-card>
    </div>

    <!-- 接口映射表 -->
    <div class="table-default mt-3">
      <el-table :data="endpoints" v-loading="loading" border>
        <el-table-column prop="name" label="接口名称" min-width="140" />
        <el-table-column prop="code" label="平台标识" min-width="150" />
        <el-table-column label="平台默认路径" min-width="200">
          <template #default="scope">
            <span class="text-gray-500">{{ scope.row.platform_path }}</span>
          </template>
        </el-table-column>
        <el-table-column label="该上游路径" min-width="260">
          <template #default="scope">
            <el-input
              v-model="scope.row.path"
              :placeholder="`默认：${scope.row.platform_path}`"
              clearable
            />
          </template>
        </el-table-column>
        <el-table-column label="方法" width="130">
          <template #default="scope">
            <el-select v-model="scope.row.method" placeholder="跟随平台" clearable class="w-full">
              <el-option label="GET" value="GET" />
              <el-option label="POST" value="POST" />
              <el-option label="PUT" value="PUT" />
              <el-option label="DELETE" value="DELETE" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column label="实际请求地址" min-width="280">
          <template #default="scope">
            <span class="text-blue-600 text-sm">{{ previewUrl(scope.row) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="启用" width="90" align="center">
          <template #default="scope">
            <el-switch v-model="scope.row.enabled" />
          </template>
        </el-table-column>
        <el-table-column label="配置状态" width="100" align="center">
          <template #default="scope">
            <el-tag v-if="scope.row.path || scope.row.method" type="success" size="small">
              已定制
            </el-tag>
            <el-tag v-else type="info" size="small">默认</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="备注" min-width="140">
          <template #default="scope">
            <el-input v-model="scope.row.remark" placeholder="可选" />
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { useRoute } from 'vue-router'
import http from '@/support/http'

const route = useRoute()

const configs = ref<any[]>([])
const endpoints = ref<any[]>([])
const loading = ref(false)
const saving = ref(false)
const configId = ref<number | null>(null)
const currentConfig = ref<any>(null)

// 支持从「三方配置」列表带 third_config_id 跳转过来并自动选中
onMounted(async () => {
  await loadConfigs()
  const preset = route.query.third_config_id
  if (preset) {
    const id = Number(preset)
    if (configs.value.some((c) => c.id === id)) {
      configId.value = id
      await loadEndpoints()
      return
    }
  }
  if (configs.value.length) {
    configId.value = configs.value[0].id
    await loadEndpoints()
  }
})

const loadConfigs = async () => {
  try {
    const { data: res } = await http.get('telegram/third/config')
    configs.value = res?.data?.data || res?.data || []
  } catch {
    configs.value = []
  }
}

const loadEndpoints = async () => {
  if (!configId.value) return
  loading.value = true
  try {
    const { data: res } = await http.get(`telegram/third/config/${configId.value}/endpoints`)
    const payload = res?.data ?? res ?? {}
    currentConfig.value = payload.third_config ?? null
    endpoints.value = Array.isArray(payload.endpoints) ? payload.endpoints : []
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '加载失败')
    endpoints.value = []
  } finally {
    loading.value = false
  }
}

// 实时预览：该接口在当前上游最终会请求的地址
const previewUrl = (row: any) => {
  const base = (currentConfig.value?.api_url || '').replace(/\/+$/, '')
  const path = (row.path || row.platform_path || '').trim()
  if (/^https?:\/\//i.test(path)) return path
  if (!base) return path
  return `${base}/${String(path).replace(/^\/+/, '')}`
}

const handleSave = async () => {
  if (!configId.value) return
  saving.value = true
  try {
    await http.put(`telegram/third/config/${configId.value}/endpoints`, {
      endpoints: endpoints.value.map((e) => ({
        code: e.code,
        path: e.path || '',
        method: e.method || '',
        enabled: e.enabled,
        remark: e.remark || '',
      })),
    })
    ElMessage.success('保存成功')
    await loadEndpoints()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

watch(configId, () => {
  currentConfig.value = null
})
</script>
