<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-form-item :label="$t('template.name')" prop="title">
      <el-input v-model="formData.title" name="title" clearable />
    </el-form-item>

    <el-form-item :label="$t('template.sendChannel')" prop="channel">
      <el-select v-model="formData.channel" name="channel">
        <el-option :label="$t('template.channelUserOption')" value="user" />
        <el-option :label="$t('template.channelBotOption')" value="bot" />
      </el-select>
      <div class="text-xs text-gray-500 mt-1">
        {{ $t('template.channelTip') }}
      </div>
    </el-form-item>

    <el-form-item :label="$t('template.content')">
      <BlocksEditor v-model="formData.blocks" />
    </el-form-item>

    <el-form-item :label="$t('template.preview')">
      <el-button size="small" @click="preview">{{ $t('template.renderPreview') }}</el-button>
      <pre v-if="previewText" class="mt-2 p-2 bg-gray-50 rounded text-xs whitespace-pre-wrap">{{ previewText }}</pre>
      <div v-if="previewEntities.length" class="text-xs text-gray-500 mt-1">
        {{ $t('template.entities') }}{{ JSON.stringify(previewEntities) }}
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
