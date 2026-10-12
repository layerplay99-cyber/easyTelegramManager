<template>
    <div>
        <!-- 验证码输入对话框 -->
        <el-dialog v-model="codeDialog.visible" :title="$t('account.codeTitle')" width="400px">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">{{ $t('account.userInfo') }}</label>
                    <p class="text-gray-600">{{ $t('account.phone') }}: {{ codeDialog.user?.phone }}</p>
                    <p class="text-gray-600">ID: {{ codeDialog.user?.id }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">{{ $t('account.code') }}</label>
                    <el-input v-model="codeDialog.code" :placeholder="$t('account.codePlaceholder')" maxlength="6" show-word-limit />
                </div>
            </div>
            <template #footer>
                <div class="dialog-footer">
                    <el-button @click="closeCodeDialog">{{ $t('system.cancel') }}</el-button>
                    <el-button type="primary" @click="submitCode" :loading="codeDialog.loading">
                        {{ $t('account.submitCode') }}
                    </el-button>
                </div>
            </template>
        </el-dialog>

        <!-- 登录二维码对话框 -->
        <el-dialog
            v-model="loginDialog.visible"
            :title="$t('account.qrLoginTitle')"
            width="500px"
            :close-on-click-modal="false"
            @close="closeLoginDialog"
        >
            <div v-loading="loginDialog.loading" style="min-height: 400px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <!-- 原来用 v-html 直接渲染后端返回的 SVG，等同于把接口内容当 HTML 执行。
                     改为以 data URI 放进 <img>：浏览器不会执行 img 里 SVG 的脚本，可规避 XSS。 -->
                <img
                    v-if="qrImageUrl"
                    :src="qrImageUrl"
                    :alt="$t('account.qrAlt')"
                    style="margin-bottom: 20px; width: 280px; height: 280px;"
                />
                <p v-if="loginDialog.qrSvg" style="color: #606266; text-align: center;">
                    {{ $t('account.qrTip') }}
                </p>
                <div v-else-if="!loginDialog.loading" style="padding: 20px; text-align: center; color: #909399;">
                    {{ $t('account.qrNotFound') }}
                </div>
            </div>
        </el-dialog>

        <!-- 2FA密码输入对话框 -->
        <el-dialog v-model="twoFADialog.visible" :title="$t('account.twoFATitle')" width="400px">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">{{ $t('account.twoFA') }}</label>
                    <el-input
                        v-model="twoFADialog.password"
                        type="password"
                        :placeholder="$t('account.twoFAPlaceholder')"
                        show-password
                        @keyup.enter="submit2FAPassword"
                    />
                    <p class="text-gray-500 text-xs mt-2">{{ $t('account.twoFATip') }}</p>
                </div>
            </div>
            <template #footer>
                <div class="dialog-footer">
                    <el-button @click="close2FADialog">{{ $t('system.cancel') }}</el-button>
                    <el-button type="primary" @click="submit2FAPassword" :loading="twoFADialog.loading">
                        {{ $t('account.submit2FA') }}
                    </el-button>
                </div>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup>
import { ref, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { useTelegramStore } from '@/stores/modules/telegram/teleApi'
import { t } from '@/support/helper'

const emit = defineEmits<{
    (e: 'loginSuccess'): void
    (e: 'refreshList'): void
}>()

const telegramStore = useTelegramStore()

// 验证码对话框相关数据
const codeDialog = ref({
    visible: false,
    code: '',
    user: null as any,
    loading: false
})

// 2FA密码对话框相关数据
const twoFADialog = ref({
    visible: false,
    password: '',
    userId: null as number | null,
    loading: false
})

// 登录二维码对话框相关数据
const loginDialog = ref({
    visible: false,
    qrSvg: '',
    userId: null as number | null,
    loading: false,
    pollTimer: null as any
})

// 把 SVG 转成 data URI，交给 <img> 渲染（避免 v-html 带来的 XSS）
const qrImageUrl = computed(() => {
    const svg = loginDialog.value.qrSvg
    if (!svg) {
        return ''
    }

    // 用 encodeURIComponent 而非 btoa：btoa 不支持非 ASCII，
    // 而二维码 SVG 里可能含中文；charset=utf-8 的 data URI 是标准写法。
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
})

// 打开验证码对话框
const openCodeDialog = (user: any) => {
    codeDialog.value.user = user
    codeDialog.value.code = ''
    codeDialog.value.visible = true
}

// 关闭验证码对话框
const closeCodeDialog = () => {
    codeDialog.value.visible = false
    codeDialog.value.user = null
    codeDialog.value.code = ''
    codeDialog.value.loading = false
}

// 提交验证码
const submitCode = async () => {
    if (!codeDialog.value.code.trim()) {
        ElMessage({
            message: t('account.codeRequired'),
            type: 'error',
            customClass: 'error-message'
        })
        return
    }

    codeDialog.value.loading = true

    try {
        const result = await telegramStore.submitTelegramUserCode(codeDialog.value.user.id, codeDialog.value.code)

        if (result.success) {
            ElMessage({
                message: t('account.codeSubmitSuccess'),
                type: 'success',
                customClass: 'success-message'
            })
            closeCodeDialog()
            emit('refreshList')
        } else {
            ElMessage({
                message: result.message || t('account.submitFailed'),
                type: 'error',
                customClass: 'error-message'
            })
        }
    } catch (error) {
        console.error('提交验证码失败:', error)
        ElMessage({
            message: t('account.submitFailedRetry'),
            type: 'error',
            customClass: 'error-message'
        })
    } finally {
        codeDialog.value.loading = false
    }
}

// 打开2FA对话框
const open2FADialog = (userId: number) => {
    twoFADialog.value.userId = userId
    twoFADialog.value.password = ''
    twoFADialog.value.visible = true
}

// 关闭2FA对话框
const close2FADialog = () => {
    twoFADialog.value.visible = false
    twoFADialog.value.userId = null
    twoFADialog.value.password = ''
    twoFADialog.value.loading = false
}

// 提交2FA密码
const submit2FAPassword = async () => {
    if (!twoFADialog.value.password.trim()) {
        ElMessage({
            message: t('account.twoFARequired'),
            type: 'error',
            customClass: 'error-message'
        })
        return
    }

    if (!twoFADialog.value.userId) {
        ElMessage({
            message: t('account.userIdLost'),
            type: 'error',
            customClass: 'error-message'
        })
        return
    }

    twoFADialog.value.loading = true

    try {
        const result = await telegramStore.complete2FALogin(twoFADialog.value.userId, twoFADialog.value.password)

        if (result.success) {
            ElMessage({
                message: t('account.twoFASuccess'),
                type: 'success',
                customClass: 'success-message'
            })
            close2FADialog()
            emit('refreshList')
        } else {
            ElMessage({
                message: result.message || t('account.twoFAFailed'),
                type: 'error',
                customClass: 'error-message'
            })
        }
    } catch (error) {
        console.error('提交2FA密码失败:', error)
        ElMessage({
            message: t('account.submitFailedRetry'),
            type: 'error',
            customClass: 'error-message'
        })
    } finally {
        twoFADialog.value.loading = false
    }
}

// 打开登录对话框
const openLoginDialog = (userId: number, qrSvg: string) => {
    loginDialog.value.userId = userId
    loginDialog.value.qrSvg = qrSvg
    loginDialog.value.visible = true
    loginDialog.value.loading = false

    // 开始轮询登录状态
    startLoginStatusPolling(userId)
}

// 关闭登录对话框
const closeLoginDialog = () => {
    stopLoginStatusPolling()
    loginDialog.value.visible = false
    loginDialog.value.qrSvg = ''
    loginDialog.value.userId = null
    loginDialog.value.loading = false
}

// 开始轮询登录状态
const startLoginStatusPolling = (userId: number) => {
    // 清除之前的定时器
    if (loginDialog.value.pollTimer) {
        clearInterval(loginDialog.value.pollTimer)
    }

    // 每 6 秒轮询一次
    loginDialog.value.pollTimer = setInterval(async () => {
        try {
            const result = await telegramStore.checkLoginStatus(userId)

            console.log('轮询登录状态:', result)

            if (result.success && result.data) {
                // 检查是否登录成功
                if (result.data.is_logged_in || result.data.login_status === 1) {
                    stopLoginStatusPolling()
                    closeLoginDialog()

                    ElMessage({
                        message: t('account.loginSuccess'),
                        type: 'success',
                        customClass: 'success-message'
                    })

                    emit('loginSuccess')
                }
                // 检查是否需要2FA
                else if (result.data.require_2fa && result.data.login_status === 2) {
                    stopLoginStatusPolling()
                    closeLoginDialog()
                    open2FADialog(userId)

                    ElMessage({
                        message: result.data.message || t('account.need2FA'),
                        type: 'warning',
                        customClass: 'warning-message'
                    })
                }
            }
        } catch (error) {
            console.error('检查登录状态失败:', error)
        }
    }, 6000)
}

// 停止轮询
const stopLoginStatusPolling = () => {
    if (loginDialog.value.pollTimer) {
        clearInterval(loginDialog.value.pollTimer)
        loginDialog.value.pollTimer = null
    }
}

// 处理登录状态广播
const handleLoginStatusBroadcast = (event: any) => {
    console.log('收到登录状态广播:', event)

    // 如果是当前登录对话框的用户，关闭二维码弹框
    if (loginDialog.value.visible && loginDialog.value.userId === event.id) {
        closeLoginDialog()
    }

    // 检查登录状态
    if (event.login_status === 1) {
        ElMessage({
            message: event.message || '登录成功！',
            type: 'success',
            customClass: 'success-message'
        })
        emit('loginSuccess')
    } else if (event.require_2fa && event.login_status === 2) {
        ElMessage({
            message: event.message || t('account.need2FA'),
            type: 'warning',
            customClass: 'warning-message'
        })
        open2FADialog(event.id)
    }
}

// 暴露方法给父组件
defineExpose({
    openCodeDialog,
    open2FADialog,
    openLoginDialog,
    closeLoginDialog,
    stopLoginStatusPolling,
    handleLoginStatusBroadcast
})
</script>
