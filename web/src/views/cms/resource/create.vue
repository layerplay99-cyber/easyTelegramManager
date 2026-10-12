<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-form-item
      :label="$t('cms.name')"
      prop="name"
      :rules="[
        {
          required: true,
          message: $t('cms.nameRequired')
        }
      ]"
    >
      <el-input v-model="formData.name" name="name" clearable />
    </el-form-item>
    <el-form-item :label="$t('cms.type')" prop="type">
      <el-radio-group v-model="formData.type">
        <el-radio-button :label="1">{{ $t('cms.carousel') }}</el-radio-button>
        <el-radio-button :label="2">{{ $t('cms.friendLink') }}</el-radio-button>
        <el-radio-button :label="3">{{ $t('cms.advertisement') }}</el-radio-button>
      </el-radio-group>
    </el-form-item>
    <el-form-item
      :label="$t('cms.content')"
      v-if="formData.type !== 2"
      prop="content"
      :rules="[
        {
          required: true,
          message: $t('cms.contentRequired')
        }
      ]"
    >
      <Upload class="w-28" action="/upload/image" :show-file-list="false" name="image" :onSuccess="uploadSuccess">
        <div class="flex flex-col">
          <img :src="formData.content" v-if="formData.content" />
          <el-button type="success" v-else size="small">{{ $t('system.pleaseSelect') }}</el-button>
        </div>
      </Upload>
    </el-form-item>
    <el-form-item :label="$t('table.link')" prop="url" :rules="[{ type: 'url', required: false, message: $t('cms.urlInvalid') }]" v-if="formData.type !== 2">
      <el-input v-model="formData.url" name="url" clearable />
    </el-form-item>
    <el-form-item :label="$t('table.link')" prop="url" :rules="[{ type: 'url', required: true, message: $t('cms.urlInvalid') }]" v-else>
      <el-input v-model="formData.url" name="url" clearable />
    </el-form-item>
    <el-form-item :label="$t('cms.description')" prop="description">
      <el-input v-model="formData.description" name="description" clearable type="textarea" />
    </el-form-item>

    <el-form-item :label="$t('cms.visible')" prop="is_visible">
      <el-radio-group v-model="formData.is_visible">
        <el-radio :label="1">{{ $t('cms.visible') }}</el-radio>
        <el-radio :label="2">{{ $t('cms.hide') }}</el-radio>
      </el-radio-group>
    </el-form-item>

    <el-form-item :label="$t('cms.openTarget')" prop="is_target">
      <el-radio-group v-model="formData.is_target">
        <el-radio :label="1">{{ $t('cms.sameWindow') }}</el-radio>
        <el-radio :label="2">{{ $t('cms.newWindow') }}</el-radio>
      </el-radio-group>
    </el-form-item>
    <div class="flex justify-end">
      <el-button type="primary" @click="submitForm(form)">{{ $t('system.confirm') }}</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
// @ts-nocheck
import { useCreate } from '@/composables/curd/useCreate'
import { useShow } from '@/composables/curd/useShow'
import { onMounted } from 'vue'

const props = defineProps({
  primary: [String, Number],
  api: String
})

const { formData, form, loading, submitForm, close } = useCreate(props.api as string, props.primary)
formData.value.type = 1
formData.value.is_visible = 1
formData.value.is_target = 1
if (props.primary) {
  useShow(props.api as string, props.primary, formData)
}

const emit = defineEmits(['close'])
onMounted(() => {
  close(() => emit('close'))
})

const uploadSuccess = (r: any) => {
  formData.value.content = r.data.path
}
</script>
