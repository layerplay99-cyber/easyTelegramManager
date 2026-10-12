<template>
  <!-- 通知 -->
  <div class="w-10 h-10 grid place-items-center rounded-full mt-2 hover:cursor-pointer" ref="messageRef" v-click-outside="onClickOutside">
    <el-badge :value="3">
      <Icon name="bell" />
    </el-badge>
    <el-popover ref="popoverRef" :virtual-ref="messageRef" trigger="hover" virtual-triggering :width="300">
      <el-tabs model-value="message">
        <el-tab-pane :label="$t('nav.messages')" name="message">
          <div>
            <div class="flex flex-row w-full border-b border-b-slate-300 dark:border-b-slate-500 mt-2" v-for="(message, key) in messages" :key="key">
              <div>
                <el-avatar src="https://cube.elemecdn.com/0/88/03b0d39583f48206768a7534e55bcpng.png" class="w-2 h-2" />
              </div>
              <div class="ml-2 h-10 mt-2">
                {{ message }}
              </div>
            </div>
          </div>
        </el-tab-pane>
        <el-tab-pane :label="$t('nav.notices')" name="notice">
          <div>
            <div class="flex flex-row w-full border-b border-b-slate-300 dark:border-b-slate-500 mt-2" v-for="(message, key) in messages" :key="key">
              <div>
                <el-avatar src="https://cube.elemecdn.com/0/88/03b0d39583f48206768a7534e55bcpng.png" class="w-2 h-2" />
              </div>
              <div class="ml-2 h-10 mt-2">
                {{ message }}
              </div>
            </div>
          </div>
        </el-tab-pane>
      </el-tabs>
    </el-popover>
  </div>
</template>
<script setup lang="ts">
import { ref, unref } from 'vue'
import { ClickOutside as vClickOutside } from 'element-plus'
import { t } from '@/support/helper'
const messageRef = ref()
const popoverRef = ref()
const onClickOutside = () => {
  unref(popoverRef).popperRef?.delayHide?.()
}

const messages = ref()

messages.value = [t('nav.friendRequest'), t('nav.licenseGrant'), t('nav.botAdminNotice')]
</script>
