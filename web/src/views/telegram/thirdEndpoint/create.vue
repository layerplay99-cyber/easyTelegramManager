<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-alert type="info" :closable="false" class="mb-4">
      {{ $t('third.specTip') }}
    </el-alert>

    <el-form-item :label="$t('third.code')" prop="code" required>
      <el-input v-model="(formData as any).code" :placeholder="$t('third.codePlaceholder')" clearable />
      <div class="text-xs text-gray-500 mt-1">{{ $t('third.codeTip') }}</div>
    </el-form-item>

    <el-form-item :label="$t('third.name')" prop="name" required>
      <el-input v-model="(formData as any).name" clearable />
    </el-form-item>

    <el-form-item :label="$t('third.requestMethod')" prop="method">
      <el-select v-model="(formData as any).method" class="w-full">
        <el-option v-for="m in methods" :key="m" :label="m" :value="m" />
      </el-select>
    </el-form-item>

    <el-form-item :label="$t('third.pathTemplate')" prop="path_template" required>
      <el-input v-model="(formData as any).path_template" placeholder="api/merchant/balance" clearable />
      <div class="text-xs text-gray-500 mt-1">{{ $t('third.pathTemplateTip') }}</div>
    </el-form-item>

    <el-form-item :label="$t('third.timeout')" prop="timeout">
      <el-input-number v-model="(formData as any).timeout" :min="1" :max="300" class="w-full" />
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
import { useCreate } from '@/composables/curd/useCreate'

const props = defineProps({
  primary: [String, Number],
  api: String,
})

const methods = ['GET', 'POST', 'PUT', 'DELETE']

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary)

if (!props.primary && formData.value) {
  ;(formData.value as any).method = 'GET'
  ;(formData.value as any).timeout = 30
  ;(formData.value as any).enabled = 1
}

const emit = defineEmits(['close'])
close(() => emit('close'))
</script>