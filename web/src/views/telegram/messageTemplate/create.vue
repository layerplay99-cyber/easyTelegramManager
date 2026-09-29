<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-form-item label="模板名称" prop="title">
      <el-input v-model="formData.title" name="title" clearable />
    </el-form-item>

    <el-form-item label="发送通道" prop="channel">
      <el-select v-model="formData.channel" name="channel">
        <el-option label="Telegram 客服账号（可发自定义/动态表情）" value="user" />
        <el-option label="Bot（只能发纯文本 / 图文）" value="bot" />
      </el-select>
      <div class="text-xs text-gray-500 mt-1">
        内容里含自定义表情 / 贴纸 / 特效时，必须用客服账号通道。
      </div>
    </el-form-item>

    <el-form-item label="消息内容">
      <BlocksEditor v-model="formData.blocks" />
    </el-form-item>

    <el-form-item label="预览">
      <el-button size="small" @click="preview">渲染预览</el-button>
      <pre v-if="previewText" class="mt-2 p-2 bg-gray-50 rounded text-xs whitespace-pre-wrap">{{ previewText }}</pre>
      <div v-if="previewEntities.length" class="text-xs text-gray-500 mt-1">
        实体：{{ JSON.stringify(previewEntities) }}
      </div>
    </el-form-item>

    <div class="flex justify-end">
      <el-button type="primary" @click="submitForm(form)">{{ $t('system.confirm') }}</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
import { ref, onMounted } from 'vue'
import http from '@/support/http'
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import BlocksEditor from './components/BlocksEditor.vue'

const props = defineProps({
  primary: [String, Number],
  api: String,
})

const { formData, form, loading, submitForm, close } = useCreate(props.api, props.primary)

formData.channel = formData.channel || 'user'
formData.blocks = formData.blocks || []

if (props.primary) {
  useShow(props.api, props.primary, formData)
}

const previewText = ref('')
const previewEntities = ref<any[]>([])

const preview = () => {
  http
    .post('telegram/message/template/preview', {
      blocks: formData.blocks,
      channel: formData.channel,
    })
    .then(r => {
      const data = r.data.data || r.data
      previewText.value = data.text || ''
      previewEntities.value = data.entities || []
    })
}

const emit = defineEmits(['close'])
onMounted(() => {
  close(() => emit('close'))
})
</script>
