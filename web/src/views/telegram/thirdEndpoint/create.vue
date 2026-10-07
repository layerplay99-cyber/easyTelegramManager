<template>
  <el-form :model="formData" label-width="130px" ref="form" v-loading="loading" class="pr-4">
    <el-form-item label="所属上游配置" prop="third_config_id" required>
      <el-select v-model="(formData as any).third_config_id" placeholder="请选择" filterable clearable class="w-full">
        <el-option v-for="c in configs" :key="c.id" :label="c.name" :value="c.id" />
      </el-select>
      <div class="text-xs text-gray-500 mt-1">地址与 token 在「三方配置」里维护</div>
    </el-form-item>

    <el-form-item label="接口名称" prop="name" required>
      <el-input v-model="(formData as any).name" placeholder="如：查询余额" clearable />
    </el-form-item>

    <el-form-item label="请求方法" prop="method">
      <el-select v-model="(formData as any).method" class="w-full">
        <el-option v-for="m in methods" :key="m" :label="m" :value="m" />
      </el-select>
    </el-form-item>

    <el-form-item label="路径模板" prop="path_template" required>
      <el-input v-model="(formData as any).path_template" placeholder="如 api/webhook/users/{userID}" clearable />
      <div class="text-xs text-gray-500 mt-1">
        支持 {占位名} 与 {{@字段名}}；占位名会在调用时按参数映射替换
      </div>
    </el-form-item>

    <el-form-item label="请求头" prop="headers">
      <el-input v-model="headersText" type="textarea" :rows="3" placeholder='JSON，如 {"Content-Type":"application/json"}' />
    </el-form-item>

    <el-form-item label="固定查询参数" prop="query">
      <el-input v-model="queryText" type="textarea" :rows="3" placeholder='JSON，如 {"version":"v1"}' />
    </el-form-item>

    <el-form-item label="超时(秒)" prop="timeout">
      <el-input-number v-model="(formData as any).timeout" :min="1" :max="300" class="w-full" />
    </el-form-item>

    <el-form-item label="是否启用" prop="enabled">
      <el-select v-model="(formData as any).enabled" class="w-full">
        <el-option label="启用" :value="1" />
        <el-option label="停用" :value="0" />
      </el-select>
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
import { useShow } from '@/composables/curd/useShow'
import { onMounted, ref, watch } from 'vue'
import http from '@/support/http'

const props = defineProps({
  primary: [String, Number],
  api: String,
})

const methods = ['GET', 'POST', 'PUT', 'DELETE']

const configs = ref<any[]>([])

const headersText = ref('')
const queryText = ref('')

const { formData, form, loading, submitForm: originalSubmitForm, close } = useCreate(props.api, props.primary)

if (props.primary) {
  const showResult = useShow(props.api, props.primary, formData)

  watch(() => showResult.loading.value, (isLoading, wasLoading) => {
    if (wasLoading === true && isLoading === false) {
      const row = formData.value as any
      headersText.value = row?.headers ? JSON.stringify(row.headers, null, 2) : ''
      queryText.value = row?.query ? JSON.stringify(row.query, null, 2) : ''
    }
  }, { immediate: true })
}

const parseJson = (text: string) => {
  if (!text || !text.trim()) return null
  try {
    const parsed = JSON.parse(text)
    return typeof parsed === 'object' ? parsed : null
  } catch {
    return null
  }
}

const submitForm = async (formEl: any) => {
  if (formData.value) {
    ;(formData.value as any).headers = parseJson(headersText.value)
    ;(formData.value as any).query = parseJson(queryText.value)
  }

  await originalSubmitForm(formEl)
}

const emit = defineEmits(['close'])

onMounted(async () => {
  close(() => emit('close'))
  try {
    const { data } = await http.get('telegram/third/config')
    configs.value = data.data?.data || data.data || []
  } catch {
    configs.value = []
  }

  if (!props.primary && formData.value) {
    ;(formData.value as any).method = 'GET'
    ;(formData.value as any).enabled = 1
    ;(formData.value as any).timeout = 30
  }
})
</script>