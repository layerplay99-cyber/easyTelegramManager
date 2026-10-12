<template>
  <div class="flex w-2/5 hover:cursor-pointer pl-1 pr-1">
    <el-dropdown size="large" placement="bottom-end" class="flex items-center justify-center hover:cursor-pointer w-full">
      <div class="flex lg:items-center">
        <img :src="userStore.getAvatar" class="w-7 h-7 rounded-full" />
        <div class="ml-2 hidden lg:block">{{ userStore.getUsername }}</div>
      </div>
      <template #dropdown>
        <el-dropdown-menu class="w-28">
          <el-dropdown-item> <Icon name="user" /> <router-link :to="{ path: '/users/center' }"> {{ $t('system.profile') }}</router-link> </el-dropdown-item>
          <el-dropdown-item divided @click="logout">
            <Icon name="power" className="mr-1 w-4 h-4" />
            {{ $t('system.logout') }}
          </el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>
  </div>
</template>

<script lang="ts" setup>
import { useUserStore } from '@/stores/modules/user'
import { useNavTabStore } from "@/stores/modules/tabs";
import Message from '@/support/message'
import { t } from '@/support/helper'

const userStore = useUserStore()
const navTabStore = useNavTabStore()

const logout = () => {
  Message.confirm(t('system.logoutConfirm'), () => {
      userStore.logout()
      navTabStore.removeAllTabs()
  })
}
</script>
