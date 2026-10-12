<template>
  <layout :title="$t('cms.contentSettings')">
    <template v-slot:body>
      <el-form :model="formData" label-width="120px" ref="form" class="pt-5 pr-4 w-full bg-white dark:bg-regal-dark">
        <div class="flex flex-col mx-auto w-2/3">
          <el-form-item :label="$t('cms.homepageDisplay')" prop="site_homepage_show">
            <el-radio-group v-model="formData.site_homepage_show">
              <el-radio :label="1">{{ $t('cms.latestPosts') }}</el-radio>
              <el-radio :label="2">{{ $t('cms.staticPage') }}</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item :label="$t('cms.staticPage')" prop="site_static_page" v-if="formData.site_homepage_show === 2">
            <el-select>
              <el-option>{{ $t('cms.samplePage') }}</el-option>
            </el-select>
          </el-form-item>

          <el-form-item :label="$t('cms.maxDisplay')" prop="site_most_article_number"> <el-input-number v-model="formData.site_most_article_number" clearable class="mr-2" /> {{ $t('cms.postsUnit') }} </el-form-item>
          <el-form-item :label="$t('cms.commentSettings')" prop="site_static_page">
            <div class="flex flex-col">
              <el-checkbox v-model="formData.site_comment_need_email" :label="$t('cms.commentNeedEmail')" size="large" />
              <el-checkbox v-model="formData.site_comment_need_login" :label="$t('cms.commentNeedLogin')" size="large" />
              <el-checkbox v-model="formData.site_comment_nested" :label="$t('cms.commentNested')" size="large" />
              <div class="w-full">
                <el-checkbox v-model="formData.site_comment_limit" size="large" :label="$t('cms.commentPaginate')" />
                {{ $t('cms.perPage') }} <el-input-number v-model="formData.site_comment_per_page" controls-position="right" class="mr-2 ml-2" />{{ $t('cms.itemsUnit') }}
              </div>
              <el-checkbox v-model="formData.site_comment_order_desc" :label="$t('cms.commentOrderDesc')" size="large" />
              <el-checkbox v-model="formData.site_comment_check" :label="$t('cms.commentCheck')" size="large" />
            </div>
          </el-form-item>

          <el-form-item :label="$t('cms.commentAvatar')" prop="site_comment_forbid_keywords">
            <el-radio-group v-model="formData.site_comment_avatar" class="flex flex-col">
              <div class="flex flex-col gap-y-1">
                <div class="flex flex-row"><el-radio label="mm" size="large" style="margin-right: 20px">{{ $t('cms.avatarUnknown') }}</el-radio><el-image class="w-8 h-8" :src="undefinedAvatar" /></div>
                <div class="flex flex-row">
                  <el-radio label="identicon" size="large" style="margin-right: 20px">{{ $t('cms.avatarIdenticon') }} </el-radio>
                  <el-image class="w-8 h-8" :src="identiconAvatar" />
                </div>
                <div class="flex flex-row">
                  <el-radio label="wavatar" size="large" style="margin-right: 20px">{{ $t('cms.avatarWavatar') }} </el-radio>
                  <el-image class="w-8 h-8" :src="wavatarAvatar" />
                </div>
                <div class="flex flex-row">
                  <el-radio label="monsterid" size="large" style="margin-right: 20px">{{ $t('cms.avatarMonsterid') }}</el-radio>
                  <el-image class="w-8 h-8" :src="monsteridAvatar" />
                </div>
                <div class="flex flex-row">
                  <el-radio label="retro" size="large" style="margin-right: 20px">{{ $t('cms.avatarRetro') }}</el-radio>
                  <el-image class="w-8 h-8" :src="retroAvatar" />
                </div>
                <div class="flex flex-row">
                  <el-radio label="robohash" style="margin-right: 20px">{{ $t('cms.avatarRobohash') }}</el-radio>
                  <el-image class="w-8 h-8" :src="robohashAvatar" />
                </div>
              </div>
            </el-radio-group>
          </el-form-item>

          <el-form-item :label="$t('cms.avatarProxy')" prop="site_comment_forbid_keywords">
            <el-input v-model="formData.site_comment_avatar_proxy" />
            <div class="text-[10px] text-gray-400">{{ $t('cms.avatarProxyTip') }}</div>
          </el-form-item>

          <el-form-item :label="$t('cms.commentForbidKeywords')" prop="site_comment_forbid_keywords">
            <el-input v-model="formData.site_comment_forbid_keywords" type="textarea" />
            <div class="text-[10px] text-gray-400">{{ $t('cms.commentForbidKeywordsTip') }}</div>
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

const avatar_proxy = 'https://gravatar.loli.net'

const defaultAvatar = '/avatar/2a2978369685974b4a90c0ef0e84a304'

const undefinedAvatar = avatar_proxy + defaultAvatar + '?d=mm'
const identiconAvatar = avatar_proxy + defaultAvatar + '?d=identicon'
const wavatarAvatar = avatar_proxy + defaultAvatar + '?d=wavatar'
const monsteridAvatar = avatar_proxy + defaultAvatar + '?d=monsterid'
const retroAvatar = avatar_proxy + defaultAvatar + '?d=retro'
const robohashAvatar = avatar_proxy + defaultAvatar + '?d=robohash'

http.get('cms/setting').then(r => {
  formData.value = r.data.data
})
</script>
