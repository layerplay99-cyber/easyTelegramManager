<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-form-item label="功能名称" prop="name">
  <el-input v-model="(formData as any).name" name="name" clearable />
</el-form-item>
<el-form-item label="功能分类" prop="category">
 <el-select v-model="(formData as any).category" placeholder="请选择" clearable>
     <el-option
       v-for="item in category"
       :key="item.value"
       :label="item.label"
       :value="item.value"
     />
   </el-select>
</el-form-item>
<el-form-item label="功能类型" prop="type">
 <el-select v-model="(formData as any).type" placeholder="请选择" clearable>
     <el-option
       v-for="item in type"
       :key="item.value"
       :label="item.label"
       :value="item.value"
     />
   </el-select>
</el-form-item>
<el-form-item label="请求类型" prop="requestType">
 <el-select v-model="(formData as any).requestType" placeholder="请选择" clearable>
     <el-option
       v-for="item in requestType"
       :key="item.value"
       :label="item.label"
       :value="item.value"
     />
   </el-select>
</el-form-item>
<el-form-item label="数据来源" prop="location">
 <el-select v-model="(formData as any).location" placeholder="请选择" clearable>
     <el-option
       v-for="item in location"
       :key="item.value"
       :label="item.label"
       :value="item.value"
     />
   </el-select>
</el-form-item>
<el-form-item label="功能标识" prop="feature">
  <el-input v-model="(formData as any).feature" name="feature" clearable />
</el-form-item>
<el-form-item label="功能描述" prop="description">
  <el-input v-model="(formData as any).description" name="description" clearable />
</el-form-item>
<el-form-item label="处理类" prop="handler">
  <el-input v-model="(formData as any).handler" name="handler" clearable />
</el-form-item>
<el-form-item label="配置项" prop="config">
  <div class="border border-gray-200 p-4 rounded w-full">
    <div class="flex items-center space-x-4 mb-4">
      <span class="text-sm font-medium">是否配置：</span>
      <el-radio-group v-model="hasConfig">
        <el-radio :label="false">无</el-radio>
        <el-radio :label="true">有</el-radio>
      </el-radio-group>
    </div>

    <div v-if="hasConfig" class="space-y-4 w-full">
      <div class="space-y-4 w-full">
        <div class="w-full">
          <label class="block text-sm font-medium mb-2">指令</label>
          <el-input v-model="configData.command" placeholder="/cx" clearable class="w-full" />
        </div>

        <div class="w-full">
          <label class="block text-sm font-medium mb-2">参数个数</label>
          <el-input-number v-model="configData.paramscount" :min="0" placeholder="1" class="w-full" />
        </div>

        <div class="w-full">
          <label class="block text-sm font-medium mb-2">参数规则</label>
          <el-input v-model="configData.rule" placeholder="正则表达式" clearable class="w-full" />
        </div>

        <div class="w-full">
          <label class="block text-sm font-medium mb-2">第三方配置</label>
          <el-select
            v-model="configData.thirdconfig"
            placeholder="请选择第三方配置"
            clearable
            class="w-full"
            :loading="thirdConfigLoading"
            multiple
          >
            <el-option
              v-for="item in thirdConfigList"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </el-select>
        </div>
      </div>
    </div>
  </div>
</el-form-item>
<el-form-item label="是否启用" prop="enabled">
 <el-select v-model="(formData as any).enabled" placeholder="请选择" clearable>
     <el-option
       v-for="item in enabled"
       :key="item.value"
       :label="item.label"
       :value="item.value"
     />
   </el-select>
</el-form-item>
    <div class="flex justify-end">
      <el-button type="primary" @click="submitForm(form)">{{ $t('system.confirm') }}</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import { onMounted, ref, reactive, watch } from 'vue'
import { useTelegramStore } from '@/stores/modules/telegram'
import http from '@/support/http'

const props = defineProps({
  primary: [String, Number],
  api: String,
})

const telegramStore = useTelegramStore()

// 第三方配置列表
const thirdConfigList = ref([])
const thirdConfigLoading = ref(false)

// 配置相关的响应式数据
const hasConfig = ref(false)
const configData = reactive({
  command: '',
  paramscount: null,
  rule: '',
  thirdconfig: null // 添加第三方配置字段
})

const category = [
    { label: '系统', value: "system" },
    { label: '客户端', value: "custom" },
    { label: '机器人', value: "bot" },
    { label: '真人', value: "realMan" }
]
const type = [
    { label: '指令', value: "command" },
    { label: '图片', value: "ocr" },
    { label: '通知', value: "notify" },
    { label: '交互', value: "interaction" },
]
const requestType = [
    { label: '消息', value: "message" },
    { label: '按钮回调', value: "callback_query" },
    { label: '内联回调', value: "inline_query" }
]
const location = [
    { label: '本地', value: "local" },
    { label: '外部', value: "external" }
]
const enabled = [
    { label: '启用', value: 1 },
    { label: '禁用', value: 0 }
]

const { formData, form, loading, submitForm: originalSubmitForm, close } = useCreate(props.api, props.primary)

// 用于获取 useShow 的返回值
let showResult: any = null

// 如果有主键，说明是编辑模式，加载数据
if (props.primary) {
  showResult = useShow(props.api, props.primary, formData)

  // 监听数据加载完成
  watch(() => showResult.loading.value, (isLoading, wasLoading) => {
    // 当加载完成时（从 true 变为 false）
    if (wasLoading === true && isLoading === false) {
      const currentConfig = (formData.value as any)?.config

      if (currentConfig && typeof currentConfig === 'string') {
        parseConfigData(currentConfig)
      }
    }
  }, { immediate: true })
}

// 加载第三方配置列表
const loadThirdConfigList = async () => {
  thirdConfigLoading.value = true
  try {
    const response = await telegramStore.loadThirdConfigList()

    // 根据实际API响应结构解析数据
    if (response && response.data.data) {
      thirdConfigList.value = response.data.data.map((item: any) => ({
        label: item.name,
        value: item.id
      }))
    }
  } catch (error) {
    console.error('加载第三方配置列表失败:', error)
  } finally {
    thirdConfigLoading.value = false
  }
}

// 自定义提交函数，处理配置项的JSON序列化
const submitForm = async (formEl: any) => {
  // 处理配置项
  let configValue = '{}'  // 默认为空的JSON字符串

  if (hasConfig.value) {
    // 清理空值，只保留有值的配置项
    const cleanConfig: any = {}
    Object.keys(configData).forEach(key => {
      const value = configData[key as keyof typeof configData]
      if (value !== '' && value !== null && value !== undefined) {
        cleanConfig[key] = value
      }
    })

    // 有实际配置内容时序列化，否则使用空的JSON字符串
    if (Object.keys(cleanConfig).length > 0) {
      configValue = JSON.stringify(cleanConfig)
    }
  }

  // 确保响应式更新
  if (formData.value) {
    ;(formData.value as any).config = configValue
  } else {
    ;(formData as any).config = configValue
  }

  // 调用原始的提交函数
  await originalSubmitForm(formEl)
}

// 解析配置数据的函数
const parseConfigData = (configString: string) => {
  try {
    const parsed = JSON.parse(configString)

    // 检查是否为空的JSON对象
    const hasConfigData = Object.keys(parsed).length > 0
    hasConfig.value = hasConfigData

    // 基本配置数据
    Object.assign(configData, {
      command: parsed.command || '',
      paramscount: parsed.paramscount || null,
      rule: parsed.rule || '',
      thirdconfig: parsed.thirdconfig || null
    })

  } catch (e) {
    hasConfig.value = false
  }
}

// 监听编辑模式，解析已有的配置
watch(() => (formData as any).config, (newConfig, oldConfig) => {
  if (newConfig && typeof newConfig === 'string') {
    parseConfigData(newConfig)
  } else if (newConfig === null || newConfig === '' || newConfig === undefined) {
    // 如果 config 为空，重置配置状态
    hasConfig.value = false
    Object.assign(configData, {
      command: '',
      paramscount: null,
      rule: '',
      thirdconfig: null
    })
  }
}, { immediate: true })

// 监听整个 formData 的变化
watch(() => formData.value, (newData) => {
  if (newData && (newData as any).config && typeof (newData as any).config === 'string') {
    parseConfigData((newData as any).config)
  }
}, { deep: true })

// 监听第三方配置列表加载完成，重新解析配置
watch(() => thirdConfigList.value, () => {
  const currentConfig = (formData.value as any)?.config || (formData as any).config
  if (thirdConfigList.value.length > 0 && currentConfig) {
    parseConfigData(currentConfig)
  }
}, { immediate: true })

const emit = defineEmits(['close'])
onMounted(() => {
  close(() => emit('close'))
  loadThirdConfigList() // 页面加载时获取第三方配置列表
})
</script>

<style scoped>
/* 确保配置项输入框与其他表单项宽度一致 */
:deep(.el-input) {
  width: 100% !important;
}

:deep(.el-input__wrapper) {
  width: 100% !important;
}

:deep(.el-input-number) {
  width: 100% !important;
}

:deep(.el-input-number .el-input__wrapper) {
  width: 100% !important;
}
</style>
