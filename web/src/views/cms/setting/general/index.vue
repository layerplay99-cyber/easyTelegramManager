<template>
  <layout :title="$t('cms.generalOptions')">
    <template v-slot:body>
      <el-form :model="formData" label-width="120px" ref="form" class="pt-5 pr-4 w-full bg-white dark:bg-regal-dark">
        <div class="flex flex-col mx-auto w-2/3">
          <el-form-item
            :label="$t('cms.siteName')"
            prop="site_title"
            :rules="[
              {
                required: true,
                message: $t('cms.siteNameRequired')
              }
            ]"
          >
            <el-input v-model="formData.site_title" clearable />
          </el-form-item>

          <el-form-item :label="$t('cms.siteLogo')" prop="site_logo">
            <Upload class="w-28" action="upload/image" :show-file-list="false" name="image" :onSuccess="uploadSuccess">
              <div class="flex flex-col">
                <img :src="formData.site_logo" v-if="formData.site_logo" />
                <el-button type="primary" v-else>{{ $t('cms.selectLogo') }}</el-button>
              </div>
            </Upload>
          </el-form-item>

          <el-form-item :label="$t('cms.siteUrl')" prop="site_url">
            <el-input v-model="formData.site_url" clearable />
          </el-form-item>

          <el-form-item :label="$t('cms.siteKeywords')" prop="site_keywords">
            <el-input v-model="formData.site_keywords" clearable />
          </el-form-item>

          <el-form-item :label="$t('cms.siteDescription')" prop="site_description">
            <el-input v-model="formData.site_description" clearable type="textarea" />
          </el-form-item>

          <el-form-item :label="$t('cms.dateFormat')" prop="site_date_format">
            <el-radio-group v-model="formData.site_date_format">
              <el-radio-button label="年-月-日">{{ $t('cms.dateFormatCn') }}</el-radio-button>
              <el-radio-button label="Y-m-d">Y-m-d</el-radio-button>
              <el-radio-button label="m/d/Y">m/d/Y</el-radio-button>
              <el-radio-button label="d/m/Y">d/m/Y</el-radio-button>
            </el-radio-group>
          </el-form-item>

          <el-form-item :label="$t('cms.timeFormat')" prop="site_time_format">
            <el-radio-group v-model="formData.site_time_format">
              <el-radio-button label="ag:i">{{ $t('cms.timeFormatAg') }}</el-radio-button>
              <el-radio-button label="g:i A">{{ $t('cms.timeFormatGA') }}</el-radio-button>
              <el-radio-button label="H:i">{{ $t('cms.timeFormatH') }}</el-radio-button>
            </el-radio-group>
          </el-form-item>

          <el-form-item :label="$t('cms.caseNumber')" prop="site_case_number">
            <el-input v-model="formData.site_case_number" clearable />
          </el-form-item>

          <el-form-item :label="$t('cms.copyright')" prop="site_case_number">
            <el-input v-model="formData.site_copyright" clearable />
          </el-form-item>

          <el-form-item :label="$t('cms.thirdPartyScript')" prop="site_script">
            <el-input v-model="formData.site_script" clearable type="textarea" />
          </el-form-item>
        </div>
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

http.get('cms/setting').then(r => {
  formData.value = r.data.data
})

const uploadSuccess = r => {
  formData.value.site_logo = r.data.path
}
</script>
