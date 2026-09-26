import { defineStore } from 'pinia'
import { ref } from 'vue'
import { generateApiSignature } from '@/support/signature'

interface ApiResponse<T = any> {
  success: boolean
  code: number
  message: string
  data?: T
}

interface operationData {
    app_id: number | string
    chatIds: Array<number | string>
    text: string
    mediaPath: string
    buttons?: any[]
    operation: string
}

export const useTelegramStore = defineStore('telegramUserApi', () => {
  const loading = ref(false)

  // 判断是否使用相对路径（开发环境或本地地址）
  const shouldUseRelativePath = () => {
    const baseUrl = import.meta.env.VITE_BASE_URL || ''
    // 如果 BASE_URL 为空或包含本地地址，使用相对路径
    return !baseUrl || baseUrl.includes('localhost') || baseUrl.includes('127.0.0.1') || baseUrl.includes('.test')
  }

  // 获取API基础URL
  const getApiUrl = () => {
    const baseUrl = import.meta.env.VITE_BASE_URL
    if (!baseUrl) {
      throw new Error('VITE_BASE_URL 环境变量未配置')
    }
    return baseUrl
  }

  // 获取认证头
  const getAuthHeaders = () => {
    const token = localStorage.getItem('catchadmin_auth_token')
    if (!token) {
      throw new Error('未找到认证令牌')
    }

    return {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    }
  }

  // 通用的API请求方法
  const apiRequest = async <T = any>(
    endpoint: string,
    options: RequestInit = {},
    customHeaders?: Record<string, string>
  ): Promise<ApiResponse<T>> => {
    try {
      loading.value = true
      const url = `${getApiUrl()}/${endpoint}`

      const response = await fetch(url, {
        headers: {
          ...getAuthHeaders(),
          ...customHeaders
        },
        ...options
      })

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}))
        return {
          success: false,
          code: response.status,
          message: errorData.message || `HTTP错误: ${response.status}`,
          data: errorData
        }
      }

      const result = await response.json()

      // 如果后端返回的数据已经包含 success 字段，直接返回
      if ('success' in result) {
        return result
      }

      // 否则包装成统一格式
      return {
        success: true,
        code: 0,
        message: result.message || 'success',
        data: result
      }
    } catch (error: any) {
      console.error('API请求失败:', error)
      return {
        success: false,
        code: 500,
        message: error.message || 'API请求失败',
        data: undefined
      }
    } finally {
      loading.value = false
    }
  }


  //输入验证码登录
  const submitTelegramUserCode = async (userId: number, code: string): Promise<ApiResponse> => {
    const requestData = { userId, code }
    const signatureHeaders = await generateApiSignature(requestData)

    return apiRequest('tg/user/login/complete', {
      method: 'POST',
      body: JSON.stringify(requestData)
    }, signatureHeaders)
  }

  //登录
  const submitTelegramUserLogin = async (userId: number): Promise<ApiResponse> => {
    const requestData = { userId }
    const signatureHeaders = await generateApiSignature(requestData)

    return apiRequest('tg/user/login', {
      method: 'POST',
      body: JSON.stringify(requestData)
    }, signatureHeaders)
  }

  // 注销
  const submitTelegramUserLogout = async (userId: number): Promise<ApiResponse> => {
    const requestData = { userId }
    const signatureHeaders = await generateApiSignature(requestData)

    return apiRequest('tg/user/logout', {
      method: 'POST',
      body: JSON.stringify(requestData)
    }, signatureHeaders)
  }

  // 检查登录状态
  const checkLoginStatus = async (userId: number): Promise<ApiResponse> => {
    const requestData = { userId }
    const signatureHeaders = await generateApiSignature(requestData)

    return apiRequest('tg/user/login/check-login-status', {
      method: 'POST',
      body: JSON.stringify(requestData)
    }, signatureHeaders)
  }

  // 提交2FA密码
  const complete2FALogin = async (userId: number, password: string): Promise<ApiResponse> => {
    const requestData = { userId, password }
    const signatureHeaders = await generateApiSignature(requestData)

    return apiRequest('tg/user/login/complete-2fa-login', {
      method: 'POST',
      body: JSON.stringify(requestData)
    }, signatureHeaders)
  }

  const operateFeature = async (type: string, data: operationData): Promise<ApiResponse> => {
    const signatureHeaders = await generateApiSignature(data)
    return apiRequest(`tg/user/operate/${type}/feature`, {
      method: 'POST',
      body: JSON.stringify(data)
    }, signatureHeaders)
  }

  const syncGroups = async (app_id: number): Promise<ApiResponse> => {
    const requestData = { app_id }
    const signatureHeaders = await generateApiSignature(requestData)

    return apiRequest('tg/user/sync/groups', {
      method: 'POST',
      body: JSON.stringify(requestData)
    }, signatureHeaders)
  }

  return {
    loading,
    submitTelegramUserCode,
    submitTelegramUserLogin,
    submitTelegramUserLogout,
    checkLoginStatus,
    complete2FALogin,
    syncGroups,
    operateFeature
  }
})
