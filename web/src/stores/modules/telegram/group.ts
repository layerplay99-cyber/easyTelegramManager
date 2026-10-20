import { defineStore } from 'pinia'
import { ref } from 'vue'
import { t } from '@/support/helper'

interface ApiResponse<T = any> {
  success: boolean
  code: number
  message: string
  data?: T
}

interface GroupConfig {
    groupId: any
    mid: any
    welcome: any
    autoReply: any
}

export const useGroup = defineStore('telegramGroup', () => {
  const loading = ref(false)

  // 获取API基础URL
  const getApiUrl = () => {
    const baseUrl = import.meta.env.VITE_BASE_URL
    if (!baseUrl) {
      throw new Error(t('system.envNotConfigured'))
    }
    return baseUrl
  }

  // 获取认证头
  const getAuthHeaders = () => {
    const token = localStorage.getItem('catchadmin_auth_token')
    if (!token) {
      throw new Error(t('system.tokenNotFound'))
    }

    return {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
        // 必须带：后端只对这个头的请求做统一包装（{code, message, data}）。
        // 少了它，这一路 fetch 拿到的就是控制器裸返回，
        // 和其它用 http(axios) 的接口结构不一致 —— 调用方就会取到 undefined
        // （表现：配置弹窗读不到商户ID、保存成功却走了失败分支）。
        'Request-from': 'Dashboard',
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
        throw new Error(t('system.httpError', { status: response.status }))
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

  /**
   * 取群配置
   *
   * 直接返回配置对象本身（不是 {code, message, data}），调用方不用关心走哪条通道：
   * 带上 Request-from: Dashboard 后后端会包一层，这里剥掉；
   * 万一哪天不带了（返回裸对象）也能正常取到。
   */
  const getGroupConfig = async (groupId: number): Promise<any> => {
    const res: any = await apiRequest(`telegram/bot/group/config/${groupId}`)

    return res?.data ?? res ?? {}
  }

  /**
   * 可选功能列表
   *
   * onlyAssigned=true 时只返回「该机器人已分配」的功能 ——
   * 群配置用它，保证群只能从机器人白名单里挑。
   */
  const getMultipleFeatures = async (
    page: number = 1,
    perPage: number = 10,
    chat_id?: number,
    category?: string,
    bot_id?: number,
    onlyAssigned: boolean = false,
    excludeIds: number[] = []
  ): Promise<ApiResponse> => {
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
    if (onlyAssigned) {
      params.append('only_assigned', '1');
    }
    if (excludeIds.length) {
      params.append('exclude_ids', excludeIds.join(','));
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

  /**
   * 只改机器人的三方上游
   *
   * 走一直都在的 apiResource 路由 PUT telegram/bots/{id}，不开新路由：
   * 新路由要生效必须重新 route:cache，没跑的环境会直接
   * 「route_not_found_or_register」。BotsRequest 已放开局部更新（改的时候
   * 字段用 sometimes，只有传了才校验/补默认值），只提交一个字段不会被 422。
   */
  const setBotThirdConfig = async (bot_id: number, third_config_id: number|null): Promise<ApiResponse> => {
    return apiRequest(`telegram/bots/${bot_id}`, {
      method: 'PUT',
      body: JSON.stringify({third_config_id})
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
    setBotThirdConfig,
    selectGroup
  }
})
