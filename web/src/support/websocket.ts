import { ref, onUnmounted } from 'vue'

export interface WebSocketOptions {
  onOpen?: (event: Event) => void
  onMessage?: (data: any) => void
  onError?: (event: Event) => void
  onClose?: (event: CloseEvent) => void
  reconnect?: boolean
  reconnectInterval?: number
  maxReconnectAttempts?: number
}

export interface WebSocketInstance {
  connect: () => void
  disconnect: () => void
  send: (data: any) => void
  isConnected: () => boolean
  getReadyState: () => number
}

/**
 * WebSocket 服务封装
 * @param url WebSocket 服务地址，如果不提供则使用环境变量中的配置
 * @param options 配置选项
 */
export function useWebSocket(url?: string, options: WebSocketOptions = {}): {
  socket: WebSocketInstance
  isConnected: any
  lastMessage: any
  error: any
} {
  const wsUrl = url || import.meta.env.VITE_WSS_URL || 'ws://localhost:8080'

  let ws: WebSocket | null = null
  let reconnectAttempts = 0
  let reconnectTimer: number | null = null

  const isConnected = ref(false)
  const lastMessage = ref<any>(null)
  const error = ref<string | null>(null)

  const {
    onOpen,
    onMessage,
    onError,
    onClose,
    reconnect = true,
    reconnectInterval = 3000,
    maxReconnectAttempts = 5
  } = options

  const connect = () => {
    try {
      // 添加认证 token
      const token = localStorage.getItem('catchadmin_auth_token')
      const connectUrl = token ? `${wsUrl}?token=${encodeURIComponent(token)}` : wsUrl

      ws = new WebSocket(connectUrl)

      ws.onopen = (event: Event) => {
        console.log('WebSocket 连接已建立')
        isConnected.value = true
        error.value = null
        reconnectAttempts = 0

        if (onOpen) {
          onOpen(event)
        }
      }

      ws.onmessage = (event: MessageEvent) => {
        try {
          const data = JSON.parse(event.data)
          lastMessage.value = data

          if (onMessage) {
            onMessage(data)
          }
        } catch (e) {
          // 如果不是 JSON 格式，直接传递原始数据
          lastMessage.value = event.data

          if (onMessage) {
            onMessage(event.data)
          }
        }
      }

      ws.onerror = (event: Event) => {
        console.error('WebSocket 连接错误:', event)
        error.value = 'WebSocket connection error'
        isConnected.value = false

        if (onError) {
          onError(event)
        }
      }

      ws.onclose = (event: CloseEvent) => {
        console.log('WebSocket 连接已关闭:', event.code, event.reason)
        isConnected.value = false
        ws = null

        if (onClose) {
          onClose(event)
        }

        // 自动重连逻辑
        if (reconnect && reconnectAttempts < maxReconnectAttempts && event.code !== 1000) {
          reconnectAttempts++
          console.log(`尝试重连 WebSocket (${reconnectAttempts}/${maxReconnectAttempts})`)

          reconnectTimer = window.setTimeout(() => {
            connect()
          }, reconnectInterval)
        }
      }

    } catch (e) {
      console.error('创建 WebSocket 连接失败:', e)
      error.value = 'Failed to create WebSocket connection'
    }
  }

  const disconnect = () => {
    if (reconnectTimer) {
      clearTimeout(reconnectTimer)
      reconnectTimer = null
    }

    if (ws) {
      ws.close(1000, 'Manual disconnect')
      ws = null
    }

    isConnected.value = false
    reconnectAttempts = 0
  }

  const send = (data: any) => {
    if (ws && ws.readyState === WebSocket.OPEN) {
      const message = typeof data === 'string' ? data : JSON.stringify(data)
      ws.send(message)
    } else {
      console.warn('WebSocket 未连接，无法发送消息')
      error.value = 'WebSocket not connected'
    }
  }

  const getIsConnected = () => {
    return ws ? ws.readyState === WebSocket.OPEN : false
  }

  const getReadyState = () => {
    return ws ? ws.readyState : WebSocket.CLOSED
  }

  // 组件卸载时自动断开连接
  onUnmounted(() => {
    disconnect()
  })

  const socket: WebSocketInstance = {
    connect,
    disconnect,
    send,
    isConnected: getIsConnected,
    getReadyState
  }

  return {
    socket,
    isConnected,
    lastMessage,
    error
  }
}

/**
 * 创建一个简单的 WebSocket 连接
 * @param url WebSocket 服务地址
 * @param onMessage 消息接收回调
 */
export function createWebSocket(url?: string, onMessage?: (data: any) => void) {
  return useWebSocket(url, {
    onMessage,
    onOpen: () => {
      console.log('WebSocket 连接成功')
    },
    onError: (event) => {
      console.error('WebSocket 连接错误:', event)
    },
    onClose: (event) => {
      console.log('WebSocket 连接关闭:', event.code, event.reason)
    }
  })
}
