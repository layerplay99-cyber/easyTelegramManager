<template>
  <div>
    <!-- 顶部：选择要配置的上游 -->
    <div class="table-default">
      <el-card shadow="never">
        <template #header>
          <div class="flex items-center justify-between">
            <span class="font-medium">{{ $t('third.upstreamPathConfig') }}</span>
            <el-button type="primary" :loading="saving" :disabled="!configId" @click="handleSave">
              {{ $t('system.save') }}
            </el-button>
          </div>
        </template>

        <el-alert type="info" show-icon :closable="false" class="mb-4">
          <template #title>
            <div class="leading-6">
              <div>{{ $t('third.upstreamPathTip') }}</div>
              <div class="mt-2">{{ $t('third.pathParamTip') }}</div>
              <!-- 示例里的花括号必须硬编码：走 $t 会被 vue-i18n 当占位符插值成空串 -->
              <div class="mt-1">
                <span class="text-gray-500">{{ $t('third.pathExample') }}：</span>
                <code class="px-1 bg-white rounded text-red-600">api/merchants/{merchant_id}/balance</code>
              </div>
            </div>
          </template>
        </el-alert>

        <el-form-item :label="$t('third.upstreamConfig')">
          <el-select
            v-model="configId"
            :placeholder="$t('third.selectUpstream')"
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
        <el-table-column prop="name" :label="$t('third.name')" min-width="140" />
        <el-table-column prop="code" :label="$t('third.platformCode')" min-width="150" />
        <el-table-column :label="$t('third.platformDefaultPath')" min-width="200">
          <template #default="scope">
            <span class="text-gray-500">{{ scope.row.platform_path }}</span>
          </template>
        </el-table-column>
        <el-table-column :label="$t('third.upstreamPath')" min-width="260">
          <!-- 表头标注占位写法：花括号是语法记号，硬编码（不走 $t），各语言通用 -->
          <template #header>
            <span>{{ $t('third.upstreamPath') }}</span>
            <code class="ml-1 text-xs text-gray-400 font-normal">{参数名}</code>
          </template>
          <template #default="scope">
            <el-input
              v-model="scope.row.path"
              :placeholder="`${$t('third.defaultPrefix')}${scope.row.platform_path}`"
              clearable
            />
          </template>
        </el-table-column>
        <el-table-column :label="$t('third.method')" width="130">
          <template #default="scope">
            <el-select v-model="scope.row.method" :placeholder="$t('third.followPlatform')" clearable class="w-full">
              <el-option label="GET" value="GET" />
              <el-option label="POST" value="POST" />
              <el-option label="PUT" value="PUT" />
              <el-option label="DELETE" value="DELETE" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column :label="$t('third.actualUrl')" min-width="280">
          <template #default="scope">
            <span class="text-blue-600 text-sm">{{ previewUrl(scope.row) }}</span>
          </template>
        </el-table-column>
        <el-table-column :label="$t('system.enabled')" width="90" align="center">
          <template #default="scope">
            <el-switch v-model="scope.row.enabled" />
          </template>
        </el-table-column>
        <el-table-column :label="$t('third.configStatus')" width="100" align="center">
          <template #default="scope">
            <el-tag v-if="scope.row.path || scope.row.method" type="success" size="small">
              {{ $t('third.customized') }}
            </el-tag>
            <el-tag v-else type="info" size="small">{{ $t('third.defaultValue') }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column :label="$t('third.remark')" min-width="140">
          <template #default="scope">
            <el-input v-model="scope.row.remark" :placeholder="$t('third.optional')" />
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
import { t } from '@/support/helper'

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
    ElMessage.error(e?.response?.data?.message || t('third.loadFailed'))
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
    ElMessage.success(t('third.saveSuccess'))
    await loadEndpoints()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('third.saveFailed'))
  } finally {
    saving.value = false
  }
}

watch(configId, () => {
  currentConfig.value = null
})
</script>
