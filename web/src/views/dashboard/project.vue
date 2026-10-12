<template>
  <div class="w-full sm:w-[27%]">
    <el-card shadow="never">
      <template #header>
        <div class="flex flex-row justify-between">
          <span class="text-lg">{{ $t('dashboard.projectInfo') }}</span>
        </div>
      </template>
      <div class="grid grid-cols-1 sm:grid-cols-1 gap-1">
        <div>
          <div class="text-xl text-center">Catch-admin</div>
          <div class="text-base text-gray-400">
            {{ $t('dashboard.projectDesc') }}
            <el-link href="https://catchadmin.com/" target="_blank">{{ $t('dashboard.more') }}</el-link>
          </div>
          <div class="flex justify-between pl-4 pr-4 mt-2 w-full sm:w-[75%] m-auto">
            <a href="https://github.com/JaguarJack/catch-admin/stargazers"><img alt="GitHub stars" src="https://img.shields.io/github/stars/JaguarJack/catch-admin" /></a>
            <a href="https://github.com/JaguarJack/catch-admin/network"><img alt="GitHub forks" src="https://img.shields.io/github/forks/JaguarJack/catch-admin" /></a>
            <a href="https://github.com/JaguarJack/catch-admin/blob/master/LICENSE.txt"><img alt="GitHub license" src="https://img.shields.io/github/license/JaguarJack/catch-admin" /></a>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-1 mt-2">
          <el-card shadow="hover">
            <div class="text-center">{{ $t('dashboard.easy') }}</div>
            <div class="text-sm text-gray-400 pt-2">{{ $t('dashboard.easyDesc') }}</div>
          </el-card>
          <el-card shadow="hover">
            <div class="text-center">{{ $t('dashboard.focused') }}</div>
            <div class="text-sm text-gray-400 pt-2">{{ $t('dashboard.focusedDesc') }}</div>
          </el-card>
          <el-card shadow="hover">
            <div class="text-center">{{ $t('dashboard.powerful') }}</div>
            <div class="text-sm text-gray-400 pt-2">{{ $t('dashboard.powerfulDesc') }}</div>
          </el-card>
        </div>
      </div>
    </el-card>
  </div>
</template>
