<template>
    <div class="bg-gray-50 h-screen flex items-center justify-center">
        <div class="flex w-full sm:w-[32rem] shadow bg-white lg:rounded-lg">
            <div class="w-full mx-auto pt-6 pb-6 pl-4 pr-4">
                <div class="flex mt-2">
                    <img :src="logo" class="mx-auto w-8" />
                </div>
                <div class="w-full text-center text-2xl mt-6 mb-8 text-indigo-700">Hi, {{ $t('login.welcome') }}</div>
                <el-divider>{{ $t('login.sign_in') }}</el-divider>

                <!-- 登录表单 -->
                <div v-if="!twoFactorSetup.show">
                    <el-form
                        ref="form"
                        :model="params"
                        status-icon
                        v-loading.fullscreen.lock="loading"
                        :rules="rules"
                        element-loading-background="rgba(0, 0, 0, 0.7)"
                        label-width="70px"
                        class="w-11/12 sm:w-4/5 pt-2 space-y-8 mx-auto"
                    >
                        <el-form-item prop="email">
                            <el-input
                                v-model="params.email"
                                type="text"
                                autocomplete="off"
                                :placeholder="$t('login.username')"
                                size="large"
                                :prefix-icon="Message"
                                class="h-12 text-base"
                            />
                        </el-form-item>

                        <el-form-item prop="password">
                            <el-input
                                v-model="params.password"
                                type="password"
                                autocomplete="off"
                                size="large"
                                :placeholder="$t('login.password')"
                                show-password
                                :prefix-icon="Lock"
                                class="h-12 text-base"
                            />
                        </el-form-item>

                        <!-- 二次验证码输入框 -->
                        <el-form-item prop="code" v-if="showCodeInput">
                            <el-input
                                v-model="params.code"
                                type="text"
                                autocomplete="off"
                                size="large"
                                placeholder="请输入6位验证码"
                                maxlength="6"
                                class="h-12 text-base"
                            >
                                <template #prefix>
                                    <el-icon><Key /></el-icon>
                                </template>
                            </el-input>
                            <div class="text-sm text-gray-500 mt-1">
                                请打开Google Authenticator输入6位验证码
                            </div>
                        </el-form-item>
                    </el-form>

                    <div class="flex justify-between w-11/12 sm:w-4/5 mx-auto mt-3" v-if="!showCodeInput">
                        <el-checkbox v-model="params.remember" class="top-2">
                            {{ $t('login.remember') }}
                        </el-checkbox>
                        <div class="text-sm pt-3 text-indigo-600 cursor-pointer">
                            {{ $t('login.lost_password') }}
                        </div>
                    </div>

                    <div class="w-11/12 sm:w-4/5 mx-auto mt-5">
                        <el-button type="primary" @click="submit(form)" size="large" class="w-full text-xl">
                            {{ showCodeInput ? '验证登录' : $t('login.sign_in') }}
                        </el-button>

                        <!-- 调试用：手动触发2FA设置 -->
                        <!-- <el-button
                            v-if="!showCodeInput && !twoFactorSetup.show"
                            @click="testTwoFactor"
                            size="small"
                            class="w-full mt-2"
                            type="info"
                        >
                            测试2FA设置 (调试用)
                        </el-button> -->
                    </div>
                </div>

                <!-- 2FA设置对话框内容 -->
                <div v-else class="w-11/12 sm:w-4/5 mx-auto">
                    <div class="text-center mb-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">设置二次验证</h3>
                        <p class="text-sm text-gray-600">请使用Google Authenticator扫描下方二维码</p>
                    </div>

                    <!-- 二维码 -->
                    <div class="flex justify-center mb-6">
                        <img :src="twoFactorSetup.qrCode" alt="QR Code" class="w-48 h-48 border rounded-lg" />
                    </div>

                    <!-- 验证码输入 -->
                    <div class="space-y-4">
                        <el-input
                            v-model="twoFactorSetup.verificationCode"
                            placeholder="请输入6位验证码"
                            maxlength="6"
                            size="large"
                            class="text-center"
                        >
                            <template #prefix>
                                <el-icon><Key /></el-icon>
                            </template>
                        </el-input>

                        <div class="text-xs text-gray-500 text-center">
                            1. 下载Google Authenticator应用<br/>
                            2. 扫描上方二维码<br/>
                            3. 输入应用中显示的6位数字
                        </div>
                    </div>

                    <!-- 操作按钮 -->
                    <div class="flex space-x-3 mt-6">
                        <el-button @click="cancelTwoFactor" size="large" class="flex-1">
                            取消
                        </el-button>
                        <el-button
                            type="primary"
                            @click="completeTwoFactor"
                            size="large"
                            class="flex-1"
                            :loading="loading"
                        >
                            完成设置
                        </el-button>
                    </div>
                </div>

                <div class="w-full text-center text-sm text-gray-400 mt-8 mb-10">
                    {{ $t('system.name') }} @copyright 2018-{{ new Date().getFullYear() }}
                </div>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { Lock, Message, Key } from '@element-plus/icons-vue'
import { onMounted } from 'vue'
import { useLogin } from './login'
import logo from '@/assets/logo.png'

const {
    params,
    loading,
    submit,
    form,
    rules,
    showCodeInput,
    twoFactorSetup,
    completeTwoFactor,
    cancelTwoFactor,
    testTwoFactor
} = useLogin()

// set default color-theme light
onMounted(() => {
    document.querySelector('html')?.setAttribute('class', 'light')
})
</script>

<style lang="scss" scoped>
:deep(.el-form-item__content) {
    margin-left: 0 !important;
}

:deep(.el-divider__text) {
    font-size: 1.25rem;
    color: rgb(148 163 184);
}

// 验证码输入框居中
:deep(.el-input__inner) {
    text-align: center;
}
</style>
