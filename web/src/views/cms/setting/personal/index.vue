<template>
  <layout :title="$t('cms.personalSettings')">
    <template v-slot:body>
      <el-form :model="formData" label-width="120px" ref="form" class="pt-5 pr-4 w-full bg-white dark:bg-regal-dark">
        <div class="flex flex-col mx-auto w-2/3">
          <el-form-item
            :label="$t('cms.nickname')"
            prop="personal_nickname"
            :rules="[
              {
                required: true,
                message: $t('cms.nicknameRequired')
              }
            ]"
          >
            <el-input v-model="formData.personal_nickname" clearable :placeholder="$t('cms.nickname')" />
          </el-form-item>
          <el-form-item :label="$t('cms.enNickname')" prop="personal_en_nickname">
            <el-input v-model="formData.personal_en_nickname" clearable :placeholder="$t('cms.enNickname')" />
          </el-form-item>
          <el-form-item :label="$t('cms.avatar')" prop="personal_avatar">
            <Upload class="w-28" action="upload/image" :show-file-list="false" name="image" :onSuccess="uploadSuccess">
              <div class="flex flex-col">
                <img :src="formData.personal_avatar" v-if="formData.personal_avatar" />
                <el-button type="primary" v-else>{{ $t('cms.selectAvatar') }}</el-button>
              </div>
            </Upload>
          </el-form-item>

          <el-form-item
            :label="$t('cms.homepage')"
            prop="personal_homepage"
            :rules="[
              {
                type: 'url',
                message: $t('cms.homepageInvalid')
              }
            ]"
          >
            <el-input v-model="formData.person_homepage" clearable :placeholder="$t('cms.homepage')" />
          </el-form-item>

          <el-form-item label="📮 " prop="personal_email">
            <el-input v-model="formData.personal_email" clearable :placeholder="$t('user.email')" />
          </el-form-item>

          <el-form-item :label="$t('cms.bio')" prop="personal_description">
            <el-input v-model="formData.personal_description" clearable type="textarea" :placeholder="$t('cms.bio')" />
          </el-form-item>

          <el-form-item :label="$t('cms.social')">
            <div class="flex flex-col gap-y-2 w-full">
              <el-input
                v-model="formData.personal_github"
                clearable
                :placeholder="$t('cms.githubHomepage')"
                :rules="[
                  {
                    type: 'url',
                    message: $t('cms.githubInvalid')
                  }
                ]"
              />
              <el-input
                v-model="formData.personal_twitter"
                clearable
                :placeholder="$t('cms.twitterHomepage')"
                :rules="[
                  {
                    type: 'url',
                    message: $t('cms.twitterInvalid')
                  }
                ]"
              />
              <el-input
                v-model="formData.personal_linkedin"
                clearable
                :placeholder="$t('cms.linkedinHomepage')"
                :rules="[
                  {
                    type: 'url',
                    message: $t('cms.linkedinInvalid')
                  }
                ]"
              />
              <el-input
                v-model="formData.personal_weibo"
                clearable
                :placeholder="$t('cms.weiboHomepage')"
                :rules="[
                  {
                    type: 'url',
                    message: $t('cms.weiboInvalid')
                  }
                ]"
              />
              <el-input
                v-model="formData.personal_google"
                clearable
                :placeholder="$t('cms.googleHomepage')"
                :rules="[
                  {
                    type: 'url',
                    message: $t('cms.googleInvalid')
                  }
                ]"
              />
              <el-input
                v-model="formData.personal_reddit"
                clearable
                :placeholder="$t('cms.redditHomepage')"
                :rules="[
                  {
                    type: 'url',
                    message: $t('cms.redditInvalid')
                  }
                ]"
              />
            </div>
          </el-form-item>

          <el-form-item :label="$t('cms.city')" prop="personal_city">
            <el-input v-model="formData.personal_city" clearable :placeholder="$t('cms.city')" />
          </el-form-item>

          <el-form-item :label="$t('system.status')" prop="personal_status">
            <el-input v-model="formData.personal_status" clearable :placeholder="$t('cms.statusPlaceholder')" />
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

http.get('cms/setting', { key: 'personal_*' }).then(r => {
  formData.value = r.data.data
})

const uploadSuccess = r => {
  formData.value.site_logo = r.data.path
}
</script>
