import { defineStore } from 'pinia'
import { ref } from 'vue'

interface ApiResponse<T = any> {
  success: boolean
  code: number
  message: string
  data?: T
}

interface GroupConfig {
    groupId: any
    mid: any
    customers: any
    welcome: any
    replyLang: any
    autoReply: any
}

export const useGroup = defineStore('telegramGroup', () => {
  const loading = ref(false)

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
        throw new Error(`HTTP错误: ${response.status}`)
      }

      const result = await response.json()
      return result
    } catch (error) {
      console.error('API请求失败:', error)
      throw error
    } finally {
      loading.value = false
    }
  }

  // 获取分组列表
  const getGroupGroupList = async (params?: Record<string, any>): Promise<ApiResponse> => {
    const queryString = params ? `?${new URLSearchParams(params).toString()}` : ''
    return apiRequest(`telegram/bot/group/group${queryString}`)
  }

  const updateGroup = async (groupId: number, data: Record<string, any>): Promise<ApiResponse> => {
    return apiRequest(`telegram/bot/groups/${groupId}`, {
      method: 'PUT',
      body: JSON.stringify(data)
    })
  }

  const selectGroup = async (bot_id: number, app_id: number, page?: number, limit?: number, name?: string): Promise<ApiResponse> => {
    let queryString = `?bot_id=${bot_id}&app_id=${app_id}`
    if (page !== undefined && limit !== undefined) {
      queryString += `&page=${page}&limit=${limit}`
    }
    if (name !== undefined && name.trim() !== '') {
      queryString += `&name=${encodeURIComponent(name)}`
    }
    return apiRequest(`telegram/bot/groups${queryString}`, {
      method: 'GET'
    })
  }

  const createOrUpdateGroupConfig = async (saveId: number, data: GroupConfig): Promise<ApiResponse> => {
    var api = `telegram/bot/group/config/${saveId}`
    var method = 'PUT'
    if(!saveId) {
      api = 'telegram/bot/group/config'
      method = 'POST'
    }
    return apiRequest(api, {
      method: method,
      body: JSON.stringify(data)
    })
  }

  const getGroupConfig = async (groupId: number): Promise<ApiResponse> => {
    return apiRequest(`telegram/bot/group/config/${groupId}`)
  }

  const getMultipleFeatures = async (page: number = 1, perPage: number = 10, chat_id?: number, category?: string, bot_id?: number): Promise<ApiResponse> => {
    const params = new URLSearchParams({
      mode: 'multiple',
      page: page.toString(),
      per_page: perPage.toString()
    });
    if (chat_id) {
      params.append('chat_id', chat_id.toString());
    }
    if (category) {
      params.append('category', category);
    }
    if (bot_id) {
      params.append('bot_id', bot_id.toString());
    }
    return apiRequest(`telegram/features?${params.toString()}`, {
      method: 'GET'
    })
  }

  const getBindFeatures = async (chat_id: number|null, bot_id: number|null, page: number = 1, perPage: number = 10): Promise<ApiResponse> => {
    const params = new URLSearchParams({
      page: page.toString(),
      per_page: perPage.toString()
    });
    if (chat_id) {
      params.append('chat_id', chat_id.toString());
    }
    if (bot_id) {
      params.append('bot_id', bot_id.toString());
    }
    return apiRequest(`telegram/feature/bind?${params.toString()}`, {
      method: 'GET'
    })
  }

  const setBindFeatures = async (chat_id: number|null, bot_id: number|null, feature_ids: number[]): Promise<ApiResponse> => {
    return apiRequest(`telegram/feature/bind/super/store`, {
      method: 'POST',
      body: JSON.stringify({chat_id, bot_id, feature_ids})
    })
  }

  return {
    loading,
    getGroupGroupList,
    updateGroup,
    createOrUpdateGroupConfig,
    getGroupConfig,
    getMultipleFeatures,
    getBindFeatures,
    setBindFeatures,
    selectGroup
  }
})
