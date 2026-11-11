<template>
  <div class="flex flex-row h-14 w-full border-b border-l dark:border-gray-800" style="background-color: var(--header-bg-color)">
    <div class="flex flex-row justify-between w-full h-14">
      <div class="flex flex-row min-w-[17rem]">
        <div class="h-full flex items-center w-8 ml-2 hover:cursor-pointer" @click="store.changeExpaned">
          <Icon name="list-bullet" class="w-6 h-8" />
        </div>
        <div class="w-96 flex items-center pl-3 sm:pl-0">
          <Breadcrumbs />
        </div>
      </div>

      <div class="flex flex-1 min-w-0 flex-row items-center pl-1 sm:pl-0 justify-end gap-2 mr-4">
        <div class="hidden sm:flex items-center shrink-0">
          <!-- 搜索 -->
          <MenuSearch />
          <!-- 多语言 -->
          <Lang />
          <!-- 暗黑主题 -->
          <Theme />
          <!-- <Notification /> -->
        </div>
        <Tenant />
        <Profile />
      </div>
    </div>
  </div>
  <Tabs/>
</template>
<script setup lang="ts">
import { useAppStore } from '@/stores/modules/app'
import Notification from './notification.vue'
import MenuSearch from './menuSearch.vue'
import Tabs from './tabs.vue'
import Tenant from './tenant.vue'
const store = useAppStore()
</script>
