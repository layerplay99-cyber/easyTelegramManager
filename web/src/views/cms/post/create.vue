<template>
  <div class="w-full" v-loading="loading">
    <el-form :model="formData" label-width="100px" ref="form" class="pt-5 pr-4 w-full bg-white dark:bg-regal-dark">
      <div class="flex md:flex-row">
        <div class="w-full">
          <div class="flex flex-row justify-between">
            <div class="w-3/4">
              <el-form-item
                :label="$t('cms.title')"
                prop="title"
                :rules="[
                  {
                    required: true,
                    message: $t('cms.postTitleRequired')
                  }
                ]"
              >
                <el-input v-model="formData.title" name="title" clearable />
              </el-form-item>
            </div>
            <div class="w-1/4">
              <el-form-item
                :label="$t('cms.selectCategory')"
                prop="category_id"
                :rules="[
                  {
                    required: true,
                    message: $t('cms.selectCategoryFirst')
                  }
                ]"
              >
                <el-tree-select v-model="formData.category_id" value-key="id" :placeholder="$t('cms.selectCategory')" clearable class="w-full" :data="category" check-strictly :props="{ value: 'id', label: 'name' }" />
              </el-form-item>
            </div>
          </div>
          <el-form-item :label="$t('cms.excerpt')" prop="excerpt" v-if="formData.type === 1">
            <el-input v-model="formData.excerpt" name="excerpt" clearable type="textarea" />
          </el-form-item>

          <el-form-item
            :label="$t('cms.content')"
            prop="content"
            :rules="[
              {
                required: true,
                message: $t('cms.postContentRequired')
              }
            ]"
          >
            <editor :height="500" v-model="formData.content" />
            <el-input v-model="formData.content" class="invisible" />
          </el-form-item>
        </div>
        <div class="w-1/3">
          <el-form-item
            class="mt-6"
            :label="$t('cms.author')"
            prop="author"
            :rules="[
              {
                required: true,
                message: $t('cms.selectAuthor')
              }
            ]"
          >
            <el-select v-model="formData.author" :placeholder="$t('cms.selectAuthorPlaceholder')">
              <el-option v-for="user in users" :key="user.id" :label="user.username" :value="user.id" />
            </el-select>
          </el-form-item>
          <el-form-item :label="$t('cms.cover')" prop="cover">
            <Upload class="w-28" action="/upload/image" :show-file-list="false" name="image" :onSuccess="uploadSuccess">
              <div class="flex flex-col">
                <img :src="formData.cover" v-if="formData.cover" />
                <div v-else class="text-sm text-indigo-500">{{ $t('cms.selectCover') }}</div>
              </div>
            </Upload>
          </el-form-item>
          <el-form-item :label="$t('cms.visibility')" prop="status" class="mt-6" v-if="formData.type === 1">
            <el-select v-model="formData.visible" :placeholder="$t('cms.selectVisibility')">
              <el-option :label="$t('cms.public')" :value="1" />
              <el-option :label="$t('cms.private')" :value="2" />
              <el-option :label="$t('cms.passwordProtected')" :value="3" />
            </el-select>
          </el-form-item>
          <el-form-item :label="$t('cms.viewPassword')" prop="password" v-if="formData.visible === 3 && formData.type === 1" class="mt-6">
            <el-input v-model="formData.password" name="password" clearable :placeholder="$t('cms.viewPasswordPlaceholder')" />
          </el-form-item>
          <el-form-item
            :label="$t('cms.tags')"
            prop="tags"
            class="mt-6"
            v-if="formData.type === 1"
            :rules="[
              {
                required: true,
                message: $t('cms.atLeastOneTag')
              }
            ]"
          >
            <el-select v-model="formData.tags" multiple filterable allow-create :placeholder="$t('cms.inputTags')" />
          </el-form-item>
          <el-form-item :label="$t('cms.canComment')" prop="is_can_comment" class="mt-6">
            <el-radio-group v-model="formData.is_can_comment">
              <el-radio-button :label="1">{{ $t('system.yes') }}</el-radio-button>
              <el-radio-button :label="2">{{ $t('system.no') }}</el-radio-button>
            </el-radio-group>
          </el-form-item>
          <el-form-item :label="$t('cms.type')" prop="type" class="mt-6">
            <el-radio-group v-model="formData.type">
              <el-radio-button :label="1">{{ $t('cms.post') }}</el-radio-button>
              <el-radio-button :label="2">{{ $t('cms.page') }}</el-radio-button>
            </el-radio-group>
          </el-form-item>
          <el-form-item :label="$t('system.status')" prop="status" class="mt-6">
            <el-radio-group v-model="formData.status">
              <el-radio-button label="1">{{ $t('cms.publish') }}</el-radio-button>
              <el-radio-button label="2">{{ $t('cms.draft') }}</el-radio-button>
            </el-radio-group>
          </el-form-item>
          <el-form-item :label="$t('cms.pinned')" prop="top" class="mt-6" v-if="formData.type === 1">
            <el-radio-group v-model="formData.top">
              <el-radio-button label="1">{{ $t('cms.category') }}</el-radio-button>
              <el-radio-button label="2">{{ $t('cms.homepage') }}</el-radio-button>
              <el-radio-button label="3">{{ $t('system.all') }}</el-radio-button>
            </el-radio-group>
          </el-form-item>
          <el-form-item :label="$t('cms.sort')" prop="order" class="mt-6">
            <el-input-number v-model="formData.order" name="order" clearable :min="1" />
          </el-form-item>

          <div class="bg-white dark:bg-regal-dark">
            <div class="flex justify-center p-3">
              <router-link to="/cms/articles/post">
                <el-button>{{ $t('system.cancel') }}</el-button>
              </router-link>
              <el-button type="primary" @click="submitForm(form)" class="ml-5">{{ $t('system.save') }}</el-button>
            </div>
          </div>
        </div>
      </div>
    </el-form>
  </div>
</template>

<script lang="ts" setup>
// @ts-nocheck
import { useCreate } from '@/composables/curd/useCreate'
import { nextTick, onMounted, ref } from 'vue'
import http from '@/support/http'
import router from '@/router'

const primary = router.currentRoute.value.params.id

const api = '/cms/post'

const { formData, form, loading, submitForm, afterCreate, afterUpdate } = useCreate(api, primary)
// 默认可评论
formData.value.is_can_comment = 1
formData.value.order = 1
formData.value.status = 2
formData.value.type = 1

afterCreate.value = () => {
  router.push({ path: '/cms/articles/post' })
}
afterUpdate.value = () => {
  router.push({ path: '/cms/articles/post' })
}

const category = ref()
const getCategory = async () => {
  await http.get('cms/category').then(r => {
    category.value = r.data.data
  })
}

const users = ref()
const getUsers = async () => {
  await http.get('users').then(r => {
    users.value = r.data.data
  })
}

const show = async () => {
  if (primary) {
    loading.value = true
    await http.get('/cms/post/' + primary).then(r => {
      nextTick(() => {
        formData.value = r.data.data
        loading.value = false
      })
    })
  }
}
onMounted(() => {
  getCategory()
  getUsers()
  show()
})

const uploadSuccess = r => {
  formData.value.cover = r.data.path
}
</script>

<style scoped>
:deep(.el-loading-mask) {
  z-index: 10000 !important;
}
</style>
