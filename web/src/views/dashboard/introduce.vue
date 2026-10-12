<template>
  <div class="w-full sm:w-[72%]">
    <el-card shadow="never">
      <template #header>
        <div class="flex flex-row justify-between">
          <span class="text-lg">{{ $t('dashboard.builtOn') }}</span>
        </div>
      </template>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-1">
        <el-card shadow="hover">
          <div class="flex justify-between">
            <Vue class="w-10 h-10" />
            <div class="text-lg">
              <el-link href="https://cn.vuejs.org/" target="_blank" :underline="false"><el-button type="success" size="small">GO</el-button></el-link>
            </div>
          </div>
          <div class="text-sm text-gray-400 pt-2">{{ $t('dashboard.vueDesc') }}</div>
        </el-card>
        <el-card shadow="hover">
          <div class="flex justify-between">
            <Laravel class="w-10 h-10" />
            <div class="text-lg">
              <el-link href="https://learnku.com/docs/laravel" target="_blank" :underline="false"><el-button type="danger" size="small">GO</el-button></el-link>
            </div>
          </div>
          <div class="text-sm text-gray-400 pt-2">{{ $t('dashboard.laravelDesc') }}</div>
        </el-card>
        <el-card shadow="hover">
          <div class="flex justify-between">
            <Element class="w-10 h-10" />
            <div class="text-lg">
              <el-link href="https://element-plus.org/" target="_blank" :underline="false"><el-button type="primary" size="small">GO</el-button></el-link>
            </div>
          </div>
          <div class="text-sm text-gray-400 pt-2">{{ $t('dashboard.elementDesc') }}</div>
        </el-card>
        <el-card shadow="hover">
          <div class="flex justify-between">
            <Tailwindcss class="w-10 h-10" />
            <div class="text-lg">
              <el-link href="https://tailwindcss.com/" target="_blank" :underline="false"><el-button size="small">GO</el-button></el-link>
            </div>
          </div>
          <div class="text-sm text-gray-400 pt-2">{{ $t('dashboard.tailwindDesc') }}</div>
        </el-card>
        <el-card shadow="hover">
          <div class="flex justify-between">
            <Composer class="w-10 h-10" />
            <div class="text-lg">
              <el-link href="https://getcomposer.org/" target="_blank" :underline="false"><el-button type="info" size="small">GO</el-button></el-link>
            </div>
          </div>
          <div class="text-sm text-gray-400 pt-2">{{ $t('dashboard.composerDesc') }}</div>
        </el-card>
        <el-card shadow="hover">
          <div class="flex justify-between">
            <Vite class="w-10 h-10" />
            <div class="text-lg">
              <el-link href="https://cn.vitejs.dev/" target="_blank" :underline="false"><el-button type="warning" size="small">Go</el-button></el-link>
            </div>
          </div>
          <div class="text-sm text-gray-400 pt-2">{{ $t('dashboard.viteDesc') }}</div>
        </el-card>
      </div>
    </el-card>

    <div>
      <el-card shadow="never" class="mt-4">
        <template #header>
          <div class="flex flex-row justify-between">
            <span class="text-lg">{{ $t('dashboard.activity') }}</span>
          </div>
        </template>
        <ul>
          <li class="flex border-b border-b-slate-200 dark:border-b-slate-700 pt-2">
            <div><el-avatar src="https://cube.elemecdn.com/0/88/03b0d39583f48206768a7534e55bcpng.png" /></div>
            <div class="flex flex-col ml-4">
              <div class="text">{{ $t('dashboard.activity1') }}</div>
              <div class="text-sm text-gray-400 mt-1 mb-2">{{ $t('dashboard.hoursAgo', { n: 1 }) }}</div>
            </div>
          </li>
          <li class="flex border-b border-b-slate-200 dark:border-b-slate-700 pt-2">
            <div><el-avatar src="https://cube.elemecdn.com/0/88/03b0d39583f48206768a7534e55bcpng.png" /></div>
            <div class="flex flex-col ml-4 mt-1">
              <div>{{ $t('dashboard.activity2') }}</div>
              <div class="text-sm text-gray-400 mt-1 mb-2">{{ $t('dashboard.hoursAgo', { n: 2 }) }}</div>
            </div>
          </li>
          <li class="flex border-b border-b-slate-200 dark:border-b-slate-700 pt-2">
            <div><el-avatar src="https://cube.elemecdn.com/0/88/03b0d39583f48206768a7534e55bcpng.png" /></div>
            <div class="flex flex-col ml-4 mt-1">
              <div>{{ $t('dashboard.activity3') }}</div>
              <div class="text-sm text-gray-400 mt-1 mb-2">{{ $t('dashboard.hoursAgo', { n: 24 }) }}</div>
            </div>
          </li>
          <li class="flex border-b border-b-slate-200 dark:border-b-slate-700 pt-2">
            <div><el-avatar src="https://cube.elemecdn.com/0/88/03b0d39583f48206768a7534e55bcpng.png" /></div>
            <div class="flex flex-col ml-4 mt-1">
              <div>{{ $t('dashboard.activity4') }}</div>
              <div class="text-sm text-gray-400 mt-1 mb-2">2021.10.26</div>
            </div>
          </li>
        </ul>
      </el-card>
    </div>
  </div>
</template>
<script lang="ts" setup>
// @ts-ignores
import Laravel from '~icons/logos/laravel'
// @ts-ignores
import Vue from '~icons/logos/vue'
// @ts-ignores
import Vite from '~icons/logos/vitejs'
// @ts-ignores
import Element from '~icons/logos/element'
// @ts-ignores
import Composer from '~icons/logos/composer'
// @ts-ignores
import Tailwindcss from '~icons/logos/tailwindcss-icon'
</script>
