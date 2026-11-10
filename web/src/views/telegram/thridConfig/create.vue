<template>
  <div>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
      <el-form-item :label="$t('third.name')" prop="name">
        <el-input v-model="formData.name" name="name" clearable />
      </el-form-item>
      <el-form-item :label="$t('third.apiUrl')" prop="api_url">
        <el-input v-model="formData.api_url" name="api_url" clearable />
      </el-form-item>
      <!-- 以下三项是「我方 → 上游」方向的凭证，与入站密钥对互不替代 -->
      <el-form-item :label="$t('third.token')" prop="token">
        <el-input v-model="formData.token" name="token" clearable />
      </el-form-item>
      <el-form-item :label="$t('third.publicKey')" prop="public_key">
        <el-input v-model="formData.public_key" name="public_key" clearable />
      </el-form-item>
      <el-form-item :label="$t('third.secretKey')" prop="secrept_key">
        <el-input v-model="formData.secrept_key" name="secrept_key" clearable />
      </el-form-item>
      <el-form-item :label="$t('third.adapter')">
        <el-select
          v-model="formData.adapter"
          clearable
          filterable
          class="w-full"
          :loading="adapterLoading"
          :placeholder="$t('third.adapterPlaceholder')"
        >
          <el-option
            v-for="o in adapterOptions"
            :key="o.value"
            :label="o.label"
            :value="o.value"
          >
            <div class="flex items-center justify-between gap-3">
              <span>
                {{ o.label }}
                <el-tag v-if="o.demo" size="small" type="warning" class="ml-1">
                  {{ $t('third.adapterDemo') }}
                </el-tag>
              </span>
              <span class="text-gray-400 text-xs">{{ o.description }}</span>
            </div>
          </el-option>
        </el-select>
        <div class="text-gray-400 text-xs mt-1">{{ $t('third.adapterTip') }}</div>
        <div v-if="hasDemoAdapters" class="text-gray-400 text-xs mt-1">{{ $t('third.adapterDemoTip') }}</div>
      </el-form-item>
      <el-form-item :label="$t('third.tenant')">
        <el-input :model-value="tenantLabel" disabled />
      </el-form-item>

      <div class="flex justify-end">
        <el-button type="primary" @click="submitForm(form)">{{ $t('system.confirm') }}</el-button>
      </div>
    </el-form>

    <!-- 上游能力声明：候选只列 needs_upstream 功能，官方 API 功能根本不出现 -->
    <div v-if="props.primary" class="mt-4 pt-4 border-t">
      <div class="mb-2 font-bold">{{ $t('third.boundFeatures') }}</div>
      <el-alert type="info" :closable="false" :title="$t('third.boundFeaturesTip')" class="mb-2" />
      <el-select
        v-model="boundFeatureIds"
        multiple
        filterable
        collapse-tags
        collapse-tags-tooltip
        class="w-full"
        :loading="featuresLoading"
        :placeholder="$t('third.selectFeatures')"
      >
        <el-option v-for="f in upstreamFeatures" :key="f.id" :label="`${f.name} · ${f.category}`" :value="f.id">
          <div class="flex justify-between gap-3">
            <span>{{ f.name }}</span>
            <span class="text-gray-400 text-xs">{{ (f.endpoints || []).join(', ') }}</span>
          </div>
        </el-option>
      </el-select>
      <div class="mt-2">
        <el-button type="primary" size="small" :loading="featuresSaving" @click="saveBoundFeatures">
          {{ $t('system.save') }}
        </el-button>
      </div>
    </div>

    <!-- 入站密钥对：绑定在这条上游配置上，明文只下发一次 -->
    <div v-if="props.primary" class="mt-4 pt-4 border-t">
      <div class="mb-2 font-bold">{{ $t('third.accessKey') }}</div>
      <el-form label-width="120px">
        <el-form-item :label="$t('third.accessKey')">
          <el-input :model-value="formData.access_key || '-'" readonly />
        </el-form-item>
        <el-form-item :label="$t('third.keyStatus')">
          <span>{{ statusLabel }} &nbsp;·&nbsp; {{ $t('third.keyVersion') }}: {{ formData.key_version || 0 }}</span>
        </el-form-item>
      </el-form>
      <el-button type="warning" size="small" :loading="keyLoading" @click="generateKeys">
        {{ $t('third.generateKeys') }}
      </el-button>
      <el-button v-if="formData.key_status === 1" size="small" :loading="keyLoading" @click="revokeKeys">
        {{ $t('third.revokeKeys') }}
      </el-button>
    </div>

    <el-dialog v-model="secretVisible" :title="$t('third.generateKeys')" width="560px">
      <el-alert type="warning" :closable="false" :title="$t('third.keyOnceNotice')" class="mb-3" />
      <p class="mb-2">access_key: <code>{{ issued.access_key }}</code></p>
      <p class="mb-2">secret_key: <code>{{ issued.secret_key }}</code></p>
      <p class="mb-2">headers: <code>{{ (issued.headers || []).join(', ') }}</code></p>
      <p class="mb-2">canonical: <code>{{ issued.canonical }}</code></p>
      <p>signature: <code>{{ issued.signature }}</code></p>
    </el-dialog>
  </div>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref } from 'vue'
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import http from '@/support/http'
import Message from '@/support/message'
import { t } from '@/support/helper'
import { Code } from '@/enum/app'
import { getActiveTenantId, loadMerchantOptions, merchantName } from '@/support/tenant'

const props = defineProps({
  primary: [String, Number],
  api: String,
})

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary)

if (props.primary) {
  useShow(props.api, props.primary, formData)
}

const emit = defineEmits(['close'])
onMounted(() => {
  close(() => emit('close'))
  loadMerchantOptions()
  loadBoundFeatures()
  loadAdapterOptions()
})

// ---- 上游协议适配器下拉 ----
// 复用已有的 telegram/features/options 路由（加一个 source 分支），不开新路由，
// 避免开了 route:cache 的环境因没重新 cache 而报 route_not_found。
const adapterOptions = ref<any[]>([])
const adapterLoading = ref(false)
const hasDemoAdapters = computed(() => adapterOptions.value.some(o => o.demo))

const loadAdapterOptions = async () => {
  adapterLoading.value = true
  try {
    const r = await http.get('telegram/features/options', {
      params: { source: 'upstream_adapters' }
    })
    adapterOptions.value = Array.isArray(r.data?.data) ? r.data.data : []
  } catch {
    adapterOptions.value = []
  } finally {
    adapterLoading.value = false
  }
}

// ---- 上游可绑定的功能 ----
const upstreamFeatures = ref<any[]>([])
const boundFeatureIds = ref<number[]>([])
const featuresLoading = ref(false)
const featuresSaving = ref(false)

const loadBoundFeatures = async () => {
  if (!props.primary) return

  featuresLoading.value = true
  try {
    const r = await http.get(`telegram/third/config/${props.primary}/features`)
    const d = r.data?.data
    upstreamFeatures.value = Array.isArray(d?.features) ? d.features : []
    boundFeatureIds.value = Array.isArray(d?.selected_ids) ? d.selected_ids : []
  } catch {
    upstreamFeatures.value = []
    boundFeatureIds.value = []
  } finally {
    featuresLoading.value = false
  }
}

const saveBoundFeatures = async () => {
  featuresSaving.value = true
  try {
    const r = await http.put(`telegram/third/config/${props.primary}/features`, {
      feature_ids: boundFeatureIds.value
    })

    if (r.data.code === Code.SUCCESS) {
      Message.success(t('featureConfig.saved'))
      await loadBoundFeatures()
    } else {
      Message.error(r.data.message)
    }
  } catch (e: any) {
    Message.error(e?.response?.data?.message || e.message)
  } finally {
    featuresSaving.value = false
  }
}

const tenantLabel = computed(() => {
  // 编辑时显示这条记录自己的归属；新建时显示顶栏当前选择
  const raw = (formData.value as any)?.tenant_id
  const id = raw === undefined || raw === null || raw === '' ? getActiveTenantId() : Number(raw)

  return merchantName(id)
})

const statusLabel = computed(() => {
  switch (Number(formData.value?.key_status || 0)) {
    case 1: return t('third.keyActive')
    case 2: return t('third.keyRevoked')
    default: return t('third.keyNone')
  }
})

const keyLoading = ref(false)
const secretVisible = ref(false)
const issued = ref<Record<string, any>>({})

const generateKeys = async () => {
  keyLoading.value = true

  try {
    const r = await http.post(`${props.api}/${props.primary}/keys`, {})

    if (r.data.code === Code.SUCCESS) {
      issued.value = r.data.data || {}
      secretVisible.value = true
      Object.assign(formData.value, {
        access_key: issued.value.access_key,
        key_status: 1,
        key_version: issued.value.key_version,
      })
    } else {
      Message.error(r.data.message)
    }
  } finally {
    keyLoading.value = false
  }
}

const revokeKeys = async () => {
  keyLoading.value = true

  try {
    const r = await http.delete(`${props.api}/${props.primary}/keys`)

    if (r.data.code === Code.SUCCESS) {
      Object.assign(formData.value, { key_status: 2 })
      Message.success(r.data.message)
    } else {
      Message.error(r.data.message)
    }
  } finally {
    keyLoading.value = false
  }
}
</script>
