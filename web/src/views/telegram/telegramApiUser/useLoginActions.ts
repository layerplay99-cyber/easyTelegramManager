import { ElMessage } from 'element-plus'
import { useTelegramStore } from '@/stores/modules/telegram/teleApi'
import { t } from '@/support/helper'

export function useLoginActions() {
    const telegramStore = useTelegramStore()

    // 提交登录
    const submitLogin = async (user: any, callbacks: {
        onNeed2FA: (userId: number) => void
        onShowQrCode: (userId: number, qrSvg: string) => void
    }) => {
        try {
            const result = await telegramStore.submitTelegramUserLogin(user.id)

            console.log('登录API返回:', result)

            if (result.success && result.data) {
                // 情况1：需要二次验证
                if (result.data.require_2fa) {
                    ElMessage({
                        message: result.data.message || t('account.need2FA'),
                        type: 'warning',
                        customClass: 'warning-message'
                    })
                    callbacks.onNeed2FA(user.id)
                    return
                }

                // 情况2：需要扫码
                if (result.data.qr_svg) {
                    callbacks.onShowQrCode(user.id, result.data.qr_svg)
                    ElMessage({
                        message: result.data.message || t('account.pleaseScanQr'),
                        type: 'success',
                        customClass: 'success-message'
                    })
                    return
                }

                // 其他情况
                ElMessage({
                    message: result.message || t('account.loginResponseAbnormal'),
                    type: 'warning',
                    customClass: 'warning-message'
                })
            } else {
                ElMessage({
                    message: result.message || t('account.loginRequestFailed'),
                    type: 'error',
                    customClass: 'error-message'
                })
            }
        } catch (error) {
            console.error('提交登录请求失败:', error)
            ElMessage({
                message: t('account.loginRequestFailedRetry'),
                type: 'error',
                customClass: 'error-message'
            })
        }
    }

    // 提交注销
    const submitLogout = async (user: any, onSuccess: () => void) => {
        try {
            const result = await telegramStore.submitTelegramUserLogout(user.id)

            if (result.success) {
                ElMessage({
                    message: t('account.logoutSuccess'),
                    type: 'success',
                    customClass: 'success-message'
                })
                onSuccess()
            } else {
                ElMessage({
                    message: result.message || t('account.logoutFailed'),
                    type: 'error',
                    customClass: 'error-message'
                })
            }
        } catch (error) {
            console.error('注销失败:', error)
            ElMessage({
                message: t('account.logoutFailedRetry'),
                type: 'error',
                customClass: 'error-message'
            })
        }
    }

    // 同步群组
    const syncGroups = async (appId: number, onSuccess: () => void) => {
        try {
            const result = await telegramStore.syncGroups(appId)

            if (result.success) {
                ElMessage({
                    message: result.message || t('account.syncGroupsSuccess'),
                    type: 'success',
                    customClass: 'success-message'
                })
                onSuccess()
            } else {
                ElMessage({
                    message: result.message || t('account.syncGroupsFailed'),
                    type: 'error',
                    customClass: 'error-message'
                })
            }
        } catch (error) {
            console.error('同步群组失败:', error)
            ElMessage({
                message: t('account.syncGroupsFailedRetry'),
                type: 'error',
                customClass: 'error-message'
            })
        }
    }

    return {
        submitLogin,
        submitLogout,
        syncGroups
    }
}
