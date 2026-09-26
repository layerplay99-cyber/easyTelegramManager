import { defineStore } from 'pinia'
import { ref } from 'vue'
import { generateApiSignature } from '@/support/signature'

interface TelegramUser {
  id: number
  app_id: string
  app_hash: string
  phone: string
  code: string
  login_status: number
  status: number
  created_at: string
  [key: string]: any
}

interface ApiResponse<T = any> {
  success: boolean
  code: number
  message: string
  data?: T
}

export const useTelegramStore = defineStore('telegram', () => {
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

  const setBotWebHook = async (botId: number): Promise<ApiResponse> => {
    // 生成API签名
    const signatureHeaders = await generateApiSignature()

    return apiRequest(`webhook/set/${botId}`, {
      method: 'PUT',
    }, signatureHeaders)
  }

  const delBotWebHook = async (botId: number): Promise<ApiResponse> => {
    // 生成API签名
    const signatureHeaders = await generateApiSignature()

    return apiRequest(`webhook/del/${botId}`, {
      method: 'put',
    }, signatureHeaders)
  }

  // 获取用户列表
  const getUserList = async (params?: Record<string, any>): Promise<ApiResponse> => {
    const queryString = params ? `?${new URLSearchParams(params).toString()}` : ''
    return apiRequest(`telegram/telegram/api/user${queryString}`)
  }

  // 获取第三方配置列表
    const loadThirdConfigList = async (): Promise<ApiResponse> => {
    return apiRequest('telegram/third/config')
    }

  // 创建用户
  const createUser = async (userData: Partial<TelegramUser>): Promise<ApiResponse> => {
    return apiRequest('telegram/telegram/api/user', {
      method: 'POST',
      body: JSON.stringify(userData)
    })
  }

  // 更新用户
  const updateUser = async (userId: number, userData: Partial<TelegramUser>): Promise<ApiResponse> => {
    return apiRequest(`telegram/telegram/api/user/${userId}`, {
      method: 'PUT',
      body: JSON.stringify(userData)
    })
  }

  // 删除用户
  const deleteUser = async (userId: number): Promise<ApiResponse> => {
    return apiRequest(`telegram/telegram/api/user/${userId}`, {
      method: 'DELETE'
    })
  }

  // 导出用户数据
  const exportUsers = async (params?: Record<string, any>): Promise<Blob> => {
    try {
      loading.value = true
      const queryString = params ? `?${new URLSearchParams(params).toString()}` : ''
      const url = `${getApiUrl()}/telegram/telegram/api/user/export${queryString}`

      const response = await fetch(url, {
        headers: getAuthHeaders()
      })

      if (!response.ok) {
        throw new Error(`导出失败: ${response.status}`)
      }

      return await response.blob()
    } catch (error) {
      console.error('导出失败:', error)
      throw error
    } finally {
      loading.value = false
    }
  }

  // 上传文件
  const uploadFile = async (file: File): Promise<ApiResponse> => {
    try {
      loading.value = true
      const formData = new FormData()
      formData.append('file', file)

      const url = `${getApiUrl()}/telegram/telegram/api/user/import`
      const token = localStorage.getItem('catchadmin_auth_token')

      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`
        },
        body: formData
      })

      if (!response.ok) {
        throw new Error(`上传失败: ${response.status}`)
      }

      return await response.json()
    } catch (error) {
      console.error('上传失败:', error)
      throw error
    } finally {
      loading.value = false
    }
  }

  // 检查导入进度
  const checkImportProgress = async (taskId: string): Promise<ApiResponse> => {
    try {
      const result = await apiRequest(`telegram/phone/import/progress?task_id=${taskId}`)
      console.log('Store - 进度查询API响应:', result)
      return result
    } catch (error) {
      console.error('Store - 进度查询API错误:', error)
      throw error
    }
  }

  // 上传手机号文件
  const uploadPhoneFile = async (file: File): Promise<ApiResponse> => {
    try {
      loading.value = true
      const formData = new FormData()
      formData.append('file', file)

      const url = `${getApiUrl()}/telegram/phone/import`
      const token = localStorage.getItem('catchadmin_auth_token')

      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`
        },
        body: formData
      })

      if (!response.ok) {
        throw new Error(`上传失败: ${response.status}`)
      }

      return await response.json()
    } catch (error) {
      console.error('上传失败:', error)
      throw error
    } finally {
      loading.value = false
    }
  }

  return {
    loading,
    getUserList,
    createUser,
    updateUser,
    deleteUser,
    exportUsers,
    uploadFile,
    checkImportProgress,
    uploadPhoneFile,
    loadThirdConfigList,
    setBotWebHook,
    delBotWebHook,
  }
})
