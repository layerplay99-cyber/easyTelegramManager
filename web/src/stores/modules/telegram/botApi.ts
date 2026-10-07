import { defineStore } from 'pinia'
import { ref } from 'vue'
import { generateApiSignature } from '@/support/signature'

interface ApiResponse<T = any> {
  success: boolean
  code: number
  message: string
  data?: T
}

interface sendGroupMessageData {
    botId: number | string
    groupId: number | string
    chatIds: Array<number | string>
    text: string
    type: string
    photo?: string
    caption?: string
    // 发送通道：bot（默认）或 user（telegram 客服账号，可发自定义/动态表情）
    channel?: 'bot' | 'user'
    telegram_user_id?: number | string
    template_id?: number | string
}

export const useBotStore = defineStore('telegramBotApi', () => {
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


  const sendGroupMessage = async (data: sendGroupMessageData): Promise<ApiResponse> => {
    const signatureHeaders = await generateApiSignature(data)
    return apiRequest(`bot/send/group`, {
      method: 'POST',
      body: JSON.stringify(data)
    }, signatureHeaders)
  }

  return {
    loading,
    sendGroupMessage,
  }
})
