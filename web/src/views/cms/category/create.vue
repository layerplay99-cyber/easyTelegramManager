<template>
  <el-form :model="formData" label-width="120px" ref="form" v-loading="loading" class="pr-4">
    <el-form-item :label="$t('cms.parentCategory')" prop="parent_id">
      <el-tree-select v-model="formData.parent_id" value-key="id" :placeholder="$t('system.pleaseSelect')" clearable class="w-full" :data="category" check-strictly :props="{ value: 'id', label: 'name' }" />
    </el-form-item>
    <el-form-item
      :label="$t('cms.categoryName')"
      prop="name"
      :rules="[
        {
          required: true,
          message: $t('cms.categoryNameRequired')
        }
      ]"
    >
      <el-input v-model="formData.name" name="name" clearable />
    </el-form-item>
    <el-form-item
      :label="$t('cms.slug')"
      prop="slug"
      :rules="[
        {
          required: true,
          message: $t('cms.slugRequired')
        }
      ]"
    >
      <el-input v-model="formData.slug" name="slug" clearable />
      <div class="text-sm text-gray-300">{{ $t('cms.slugTip1') }}</div>
      <div class="text-sm text-gray-300">{{ $t('cms.slugTip2') }}</div>
    </el-form-item>
    <el-form-item :label="$t('cms.type')" prop="type">
      <el-select v-model="formData.type" :placeholder="$t('cms.selectType')">
        <el-option v-for="item in cateTypes" :key="item.value" :label="item.name" :value="item.value" />
      </el-select>
    </el-form-item>
    <el-form-item
      :label="$t('cms.linkAddress')"
      prop="href"
      v-if="formData.type === 2"
      :rules="[
        {
          type: 'url',
          required: true,
          message: $t('cms.linkAddressInvalid')
        }
      ]"
    >
      <el-input v-model="formData.href" name="href" :placeholder="$t('cms.linkAddressPlaceholder')" />
    </el-form-item>
    <el-form-item :label="$t('cms.sort')" prop="order">
      <el-input-number v-model="formData.order" name="order" :min="1" />
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
import { onMounted, ref } from 'vue'
import http from '@/support/http'

const props = defineProps({
  primary: [String, Number],
  api: String
})

const { formData, form, loading, submitForm, close } = useCreate(props.api as string, props.primary)
// 排序默认值
formData.value.order = 1
formData.value.type = 1

if (props.primary) {
  const { afterShow } = useShow(props.api as string, props.primary, formData)
  afterShow.value = (form: any) => {
    if (form.value.parent_id === 0) {
      delete form.value.parent_id
    }
  }
}

const category = ref()
const getCategory = () => {
  http.get(props.api as string).then(r => {
    category.value = r.data.data
  })
}

const cateTypes = ref([])
const getCategoryTypes = () => {
  http.get('cms/setting/site_category_types').then(r => {
    cateTypes.value = r.data.data.site_category_types
  })
}
const emit = defineEmits(['close'])
getCategory()
onMounted(() => {
  close(() => emit('close'))

  getCategoryTypes()
})
</script>
