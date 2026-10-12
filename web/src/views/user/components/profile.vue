<template>
  <el-form :model="profile" ref="form" v-loading="loading" label-position="top">
    <Upload imageClass="w-28 h-28 rounded-full mx-auto" v-model="profile.avatar" />
    <el-form-item
      :label="$t('user.nickname')"
      prop="username"
      class="mt-2"
      :rules="[
        {
          required: true,
          message: $t('user.nicknameRequired')
        }
      ]"
    >
      <el-input v-model="profile.username" :placeholder="$t('user.nicknamePlaceholder')" />
    </el-form-item>
    <el-form-item
      :label="$t('user.email')"
      prop="email"
      :rules="[
        {
          required: true,
          message: $t('user.emailRequired')
        },
        {
          type: 'email',
          message: $t('user.emailInvalid')
        }
      ]"
    >
      <el-input v-model="profile.email" :placeholder="$t('user.emailPlaceholder')" />
    </el-form-item>
    <el-form-item
      :label="$t('user.password')"
      prop="password"
      :rules="[
        {
          pattern: /^(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{6,20}$/,
          message: $t('user.passwordPattern')
        }
      ]"
    >
      <el-input v-model="profile.password" type="password" show-password :placeholder="$t('user.passwordPlaceholder')" />
    </el-form-item>
    <div class="flex justify-center">
      <el-button type="primary" @click="submitForm(form)">{{ $t('system.update') }}</el-button>
    </div>
  </el-form>
</template>

<script lang="ts" setup>
import { onMounted, ref } from 'vue'
import { useCreate } from '@/composables/curd/useCreate'
import http from '@/support/http'
import { Code } from '@/enum/app'
import Message from '@/support/message'
import { useUserStore } from '@/stores/modules/user'

interface profile {
  avatar: string
  username: string
  email: string
  password: string
}

const profile = ref<profile>({
  avatar: '',
  username: '',
  email: '',
  password: ''
})
const { form, loading, submitForm, afterCreate } = useCreate('user/online', null, profile)

const getUserInfo = () => {
  loading.value = true
  http.get('user/online').then(r => {
    profile.value.username = r.data.data.username
    profile.value.avatar = r.data.data.avatar
    profile.value.email = r.data.data.email
    loading.value = false
  })
}

onMounted(() => {
  getUserInfo()
})

const userStore = useUserStore()
afterCreate.value = () => {
  userStore.getUserInfo()
}
</script>

<style scoped lang="scss">
:deep(.el-upload) {
  @apply h-full w-full;
}
</style>
