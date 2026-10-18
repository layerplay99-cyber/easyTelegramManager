<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-alert type="info" :closable="false" class="mb-4">
      {{ $t('third.platformDefaultTip') }}
    </el-alert>

    <!-- 代码声明的字段：只读。改了也会被下一次同步覆盖 -->
    <el-form-item :label="$t('third.code')" prop="code">
      <el-input v-model="(formData as any).code" disabled />
      <div class="text-xs text-gray-500 mt-1">{{ $t('third.codeDefined') }}</div>
    </el-form-item>

    <el-form-item :label="$t('third.name')" prop="name">
      <el-input v-model="(formData as any).name" disabled />
      <div class="text-xs text-gray-500 mt-1">{{ $t('third.codeDefined') }}</div>
    </el-form-item>

    <el-form-item :label="$t('third.requestMethod')" prop="method">
      <el-input v-model="(formData as any).method" disabled />
      <div class="text-xs text-gray-500 mt-1">{{ $t('third.codeDefined') }}</div>
    </el-form-item>

    <el-form-item :label="$t('third.pathTemplate')" prop="path_template">
      <el-input v-model="(formData as any).path_template" disabled />
      <div class="text-xs text-gray-500 mt-1">{{ $t('third.codeDefined') }}</div>
    </el-form-item>

    <!-- 以下为平台级默认值，同步不会覆盖，可以在这里改 -->
    <el-divider content-position="left">{{ $t('third.defaultValue') }}</el-divider>

    <el-form-item :label="$t('third.timeout')" prop="timeout">
      <el-input-number v-model="(formData as any).timeout" :min="1" :max="300" class="w-full" />
    </el-form-item>

    <el-form-item :label="$t('system.enabled')" prop="enabled">
      <el-switch
        v-model="(formData as any).enabled"
        :active-value="1"
        :inactive-value="0"
      />
    </el-form-item>

    <el-form-item label="headers" prop="headers">
      <el-input
        v-model="headersText"
        type="textarea"
        :rows="3"
        placeholder='{"Authorization": "Bearer xxx"}'
      />
    </el-form-item>

    <el-form-item label="query" prop="query">
      <el-input
        v-model="queryText"
        type="textarea"
        :rows="3"
        placeholder='{"channel_id": "123"}'
      />
    </el-form-item>

    <el-form-item :label="$t('third.remark')" prop="remark">
      <el-input v-model="(formData as any).remark" clearable />
    </el-form-item>

    <div class="flex justify-end">
      <el-button type="primary" @click="submitForm(form)">{{ $t('system.confirm') }}</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
import { computed } from 'vue'
import { useCreate } from '@/composables/curd/useCreate'

const props = defineProps({
  primary: [String, Number],
  api: String,
})

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary)

/**
 * headers / query 是 json 字段：库里读出来是对象，编辑框要展示成字符串，
 * 提交时以字符串回传（后端 normalize() 会把它解回数组）。留空视为清空。
 */
const toText = (v: any) => {
  if (v === null || v === undefined || v === '') return ''
  return typeof v === 'string' ? v : JSON.stringify(v, null, 2)
}

const headersText = computed({
  get: () => toText((formData.value as any)?.headers),
  set: (v: string) => {
    if (formData.value) (formData.value as any).headers = v
  },
})

const queryText = computed({
  get: () => toText((formData.value as any)?.query),
  set: (v: string) => {
    if (formData.value) (formData.value as any).query = v
  },
})

const emit = defineEmits(['close'])
close(() => emit('close'))
</script>
