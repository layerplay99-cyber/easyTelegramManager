import { ElMessage } from 'element-plus'
import { useTelegramStore } from '@/stores/modules/telegram/teleApi'

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
                        message: result.data.message || '需要输入两步验证密码',
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
                        message: result.data.message || '请扫码登录',
                        type: 'success',
                        customClass: 'success-message'
                    })
                    return
                }

                // 其他情况
                ElMessage({
                    message: result.message || '登录请求返回异常',
                    type: 'warning',
                    customClass: 'warning-message'
                })
            } else {
                ElMessage({
                    message: result.message || '登录请求失败',
                    type: 'error',
                    customClass: 'error-message'
                })
            }
        } catch (error) {
            console.error('提交登录请求失败:', error)
            ElMessage({
                message: '登录请求失败，请稍后重试',
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
                    message: '注销成功',
                    type: 'success',
                    customClass: 'success-message'
                })
                onSuccess()
            } else {
                ElMessage({
                    message: result.message || '注销失败',
                    type: 'error',
                    customClass: 'error-message'
                })
            }
        } catch (error) {
            console.error('注销失败:', error)
            ElMessage({
                message: '注销失败，请稍后重试',
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
                    message: result.message || '同步群组成功',
                    type: 'success',
                    customClass: 'success-message'
                })
                onSuccess()
            } else {
                ElMessage({
                    message: result.message || '同步群组失败',
                    type: 'error',
                    customClass: 'error-message'
                })
            }
        } catch (error) {
            console.error('同步群组失败:', error)
            ElMessage({
                message: '同步群组失败，请稍后重试',
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
