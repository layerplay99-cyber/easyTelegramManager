<template>
  <layout :title="$t('cms.other')">
    <template v-slot:body>
      <el-form :model="formData" label-width="120px" ref="form" class="pt-5 pr-4 w-full bg-white dark:bg-regal-dark">
        <div class="flex flex-col mx-auto w-2/3">
          <el-form-item :label="$t('cms.categoryTypes')" prop="site_category_types">
            <div class="flex flex-col gap-y-2 w-full">
              <div class="flex flex-row gap-x-2" v-for="(item, k) in formData.site_category_types">
                <div class="flex flex-row gap-x-2 w-2/3">
                  <el-input :placeholder="$t('cms.typeName')" v-model="item.name" />
                  <el-input :placeholder="$t('cms.templateName')" v-model="item.template" />
                </div>
                <div class="w-1/3">
                  <el-input-number :placeholder="$t('cms.typeValue')" :min="1" style="width: 100px" v-model="item.value" controls-position="right" v-if="item.is_default" disabled />
                  <el-input-number :placeholder="$t('cms.typeValue')" :min="1" style="width: 120px" v-model="item.value" controls-position="right" v-else />
                  <el-button type="danger" size="small" class="ml-2" v-if="!item.is_default" @click="deleteCateType(k)"><Icon name="trash" className="w-4 h-4" /></el-button>
                </div>
              </div>
            </div>
          </el-form-item>
          <div class="flex justify-center w-full">
            <el-button type="primary" class="ml-5 w-[5rem]" @click="addCateType"><Icon name="plus" className="w-4 h-4" />{{ $t('system.add') }}</el-button>
          </div>

          <el-divider content-position="center">{{ $t('cms.urlSettings') }}</el-divider>

          <el-form-item :label="$t('cms.simpleMode')">
            <el-radio-group v-model="formData.is_simple_url">
              <el-radio-button :label="1" size="small">{{ $t('system.yes') }}</el-radio-button>
              <el-radio-button :label="2" size="small">{{ $t('system.no') }}</el-radio-button>
            </el-radio-group>
            <div class="pl-3 text-sm text-gray-400">{{ $t('cms.simpleModeTip') }}</div>
          </el-form-item>
          <el-form-item :label="$t('cms.linkStructure')">
            <el-radio-group v-model="formData.site_url_struct" class="flex flex-col justify-center">
              <div class="w-full">
                <el-radio :label="1">{{ $t('cms.dynamicMode') }}<span class="ml-3 text-sm text-gray-400">https://domain.com/post?id=1</span> </el-radio>
              </div>
              <div class="w-full">
                <el-radio :label="2">{{ $t('cms.staticMode') }}<span class="ml-3 text-sm text-gray-400">https://domain.com/post/{id}</span> </el-radio>
              </div>
              <div class="w-full">
                <el-radio :label="3"
                  >{{ $t('cms.monthDayStructure') }} <span class="ml-3 text-sm text-gray-400">https://domain.com/{{ date.getMonth() }}/{{ date.getDay() }}/{id}</span>
                </el-radio>
              </div>
              <div class="w-full">
                <el-radio :label="4"
                  >{{ $t('cms.yearMonthDayStructure') }} <span class="ml-3 text-sm text-gray-400">https://domain.com/{{ date.getFullYear() }}/{{ date.getMonth() }}/{{ date.getDay() }}/{id}</span>
                </el-radio>
              </div>
            </el-radio-group>
          </el-form-item>

          <el-form-item :label="$t('cms.urlSuffix')">
            <el-input :placeholder="$t('cms.urlSuffix')" v-model="formData.site_url_suffix" style="width: 200px" />
            <div class="ml-3 text-sm text-gray-400">{{ $t('cms.urlSuffixTip') }}</div>
          </el-form-item>
        </div>
        <el-divider content-position="center" />

        <div class="flex justify-center mb-3 bg-white dark:bg-regal-dark">
          <el-button type="primary" @click="submitForm(form)" class="ml-5 w-[10rem]">{{ $t('system.save') }}</el-button>
        </div>
      </el-form>
    </template>
  </layout>
</template>

<script setup lang="ts">
// @ts-nocheck
import Layout from '../layout.vue'

import { useCreate } from '@/composables/curd/useCreate'
import http from '@/support/http'
const api = '/cms/setting'

const { formData, form, loading, submitForm } = useCreate(api)
formData.value.site_url_struct = 1
formData.value.is_simple_url = 2

const date = new Date()

http.get('cms/setting').then(r => {
  formData.value = r.data.data
})

const addCateType = () => {
  const latest = formData.value.site_category_types[formData.value.site_category_types.length - 1]
  formData.value.site_category_types.push({
    name: '',
    template: '',
    value: latest.value + 1,
    is_default: false
  })
}
const deleteCateType = deleteK => {
  let newArr = []
  formData.value.site_category_types.forEach((item, k) => {
    if (deleteK !== k) {
      newArr.push(item)
    }
  })

  formData.value.site_category_types = newArr
}
</script>
