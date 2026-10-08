<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-alert type="info" :closable="false" class="mb-4">
      平台接口规范由后端代码统一定义（接入标准），供各上游厂商按此实现。
      新增接口请在 PlatformEndpointRegistry 中添加定义后执行 telegram:sync-features。
    </el-alert>

    <el-form-item label="接口标识 code" prop="code" required>
      <el-input v-model="(formData as any).code" placeholder="如 merchant.balance" clearable />
      <div class="text-xs text-gray-500 mt-1">平台唯一标识，功能按它引用</div>
    </el-form-item>

    <el-form-item label="接口名称" prop="name" required>
      <el-input v-model="(formData as any).name" clearable />
    </el-form-item>

    <el-form-item label="请求方法" prop="method">
      <el-select v-model="(formData as any).method" class="w-full">
        <el-option v-for="m in methods" :key="m" :label="m" :value="m" />
      </el-select>
    </el-form-item>

    <el-form-item label="路径模板" prop="path_template" required>
      <el-input v-model="(formData as any).path_template" placeholder="api/merchant/balance" clearable />
      <div class="text-xs text-gray-500 mt-1">各上游路径可不同，这里填平台约定的相对路径</div>
    </el-form-item>

    <el-form-item label="超时(秒)" prop="timeout">
      <el-input-number v-model="(formData as any).timeout" :min="1" :max="300" class="w-full" />
    </el-form-item>

    <el-form-item label="备注" prop="remark">
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