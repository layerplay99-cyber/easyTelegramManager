<template>
    <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
        <el-form-item label="Api_Token" prop="api_token">
            <el-input v-model="formData.api_token" name="api_token" clearable />
        </el-form-item>
        <el-form-item label="Bot用户名" prop="username">
            <el-input v-model="formData.username" name="username" clearable />
        </el-form-item>
        <el-form-item label="Url_Token" prop="url_token">
            <el-input v-model="formData.url_token" name="url_token" clearable />
        </el-form-item>
        <el-form-item label="Webhook 指向" prop="webhook_target">
            <el-radio-group v-model="webhookTarget">
                <el-radio value="platform">当前平台</el-radio>
                <el-radio value="custom">自定义外链</el-radio>
            </el-radio-group>
        </el-form-item>
        <el-form-item label="WebHookUrl" prop="webhook_url">
            <el-input
                v-model="formData.webhook_url"
                name="webhook_url"
                clearable
                :disabled="webhookTarget === 'platform'"
                :placeholder="webhookTarget === 'platform' ? '使用当前平台回调（无需填写）' : '请输入外部 Webhook URL'"
            />
            <div v-if="webhookTarget === 'platform'" class="text-gray-400 text-xs mt-1">
                将自动使用当前平台回调地址：{{ platformWebhookUrl }}
            </div>
        </el-form-item>
        <!-- 三方上游：仅超级管理员可见/可改（后端也已硬校验） -->
        <el-form-item v-if="isSuperAdmin" label="三方上游" prop="third_config_id">
            <el-select
                v-model="formData.third_config_id"
                placeholder="未指定（该机器人无法调用三方接口）"
                clearable
                filterable
                class="w-full"
                :loading="thirdLoading"
            >
                <el-option
                    v-for="c in thirdConfigs"
                    :key="c.id"
                    :label="c.name"
                    :value="c.id"
                />
            </el-select>
            <div class="text-xs text-gray-500 mt-1">
                该机器人调用三方接口时使用的上游（地址 + Token）。不同机器人可指向不同上游。
            </div>
        </el-form-item>
        <el-form-item label="Bot描述" prop="description">
            <el-input v-model="formData.description" name="description" clearable />
        </el-form-item>
        <el-form-item v-show="false" label="状态" prop="enabled">
            <el-select v-model="formData.enabled" placeholder="请选择" clearable>
                <el-option v-for="item in enabled" :key="item.value" :label="item.label" :value="item.value" />
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
import { computed, onMounted, ref, watch } from 'vue'
import http from '@/support/http'
import { useUserStore } from '@/stores/modules/user'

const props = defineProps({
    primary: [String, Number],
    api: String,
})

const enabled = ref([
  { value: 1, label: '启用' },
    { value: 0, label: '禁用' }
])

// 三方上游：仅超级管理员可见/可改（上游涉及地址与 Token，属成本与安全边界）
const userStore = useUserStore()
const isSuperAdmin = userStore.isSuperAdmin()

const thirdConfigs = ref<any[]>([])
const thirdLoading = ref(false)

const loadThirdConfigs = async () => {
  if (!isSuperAdmin) return
  thirdLoading.value = true
  try {
    const { data } = await http.get('telegram/third/config')
    thirdConfigs.value = data.data?.data || data.data || []
  } catch {
    thirdConfigs.value = []
  } finally {
    thirdLoading.value = false
  }
}

onMounted(loadThirdConfigs)

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary)

// 新增机器人默认「待激活」(enabled=0)：避免在库里直接默认已激活、
// 却又没触发 setWebhook 的坏状态；由用户在列表手动开启开关来触发 setWebhook。
if (!props.primary) {
  formData.value.enabled = 0
}

// Webhook 指向：默认「当前平台」。选当前平台时不需要填写 webhook_url，
// 提交时留空，由后端 BotsRequest 默认填 APP_URL.'/api/webhook/pull'（当前平台回调）。
const webhookTarget = ref<'platform' | 'custom'>('platform')
const platformWebhookUrl = computed(() => `${window.location.origin}/api/webhook/pull`)

// 切换到「当前平台」时清空输入，交由后端默认填充。
// 用 immediate 让新建时也在挂载即清空（否则初始 null 不会触发 watch，导致 webhook_url 以 null 提交、后端不填充）。
watch(webhookTarget, (val) => {
  if (val === 'platform') {
    formData.value.webhook_url = ''
  }
}, { immediate: true })

// 编辑回填：根据已存 webhook_url 判断是平台回调还是自定义外链
if (props.primary) {
  const show = useShow(props.api, props.primary, formData)
  show.afterShow.value = () => {
    const url = (formData.value.webhook_url || '') as string
    // 以平台回调路径后缀识别：命中则为「当前平台」，否则视为「自定义外链」
    if (url && !url.endsWith('/api/webhook/pull')) {
      webhookTarget.value = 'custom'
    } else {
      webhookTarget.value = 'platform'
      formData.value.webhook_url = ''
    }
  }
}

const emit = defineEmits(['close'])
onMounted(() => {
    close(() => emit('close'))
})
</script>
