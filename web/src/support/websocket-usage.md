# WebSocket 服务使用说明

## 配置

在 `.env` 文件中配置 WebSocket 服务地址：

```properties
VITE_WSS_URL=ws://localhost:8080
# 或者使用 wss:// 用于安全连接
# VITE_WSS_URL=wss://your-domain.com/ws
```

## 基本使用

### 1. 简单连接和消息监听

```vue
<script setup>
import { useWebSocket } from '@/support/websocket'

// 使用环境变量中的默认 WSS URL
const { socket, isConnected, lastMessage } = useWebSocket(undefined, {
  onMessage: (data) => {
    console.log('收到消息:', data)
  }
})

// 连接 WebSocket
socket.connect()

// 发送消息
socket.send({ type: 'hello', message: 'Hello WebSocket!' })

// 断开连接
socket.disconnect()
</script>
```

### 2. 自定义 URL 和完整配置

```vue
<script setup>
import { useWebSocket } from '@/support/websocket'

const { socket, isConnected, lastMessage, error } = useWebSocket('ws://custom-url:8080', {
  onOpen: (event) => {
    console.log('连接已建立')
  },
  onMessage: (data) => {
    console.log('收到消息:', data)
    // 处理不同类型的消息
    switch(data.type) {
      case 'notification':
        ElMessage.info(data.message)
        break
      case 'progress':
        console.log(`进度: ${data.progress}%`)
        break
    }
  },
  onError: (event) => {
    console.error('连接错误:', event)
  },
  onClose: (event) => {
    console.log('连接关闭:', event.code, event.reason)
  },
  reconnect: true, // 是否自动重连
  reconnectInterval: 5000, // 重连间隔(ms)
  maxReconnectAttempts: 3 // 最大重连次数
})

onMounted(() => {
  socket.connect()
})
</script>
```

### 3. 在模板中显示连接状态

```vue
<template>
  <div>
    <el-badge :value="isConnected ? '已连接' : '未连接'" 
              :type="isConnected ? 'success' : 'danger'">
      <el-button @click="socket.connect()">连接 WebSocket</el-button>
    </el-badge>
    
    <el-button @click="socket.disconnect()">断开连接</el-button>
    
    <el-button @click="sendMessage">发送消息</el-button>
    
    <div v-if="lastMessage">
      最后收到的消息: {{ lastMessage }}
    </div>
  </div>
</template>

<script setup>
const sendMessage = () => {
  socket.send({
    type: 'chat',
    message: 'Hello from Vue!'
  })
}
</script>
```

### 4. 快速创建简单连接

```vue
<script setup>
import { createWebSocket } from '@/support/websocket'

// 快速创建连接
const { socket, isConnected, lastMessage } = createWebSocket(undefined, (data) => {
  console.log('收到消息:', data)
})

onMounted(() => {
  socket.connect()
})
</script>
```

## API 文档

### useWebSocket(url?, options?)

#### 参数
- `url` (string, 可选): WebSocket 服务地址，不提供则使用环境变量 VITE_WSS_URL
- `options` (object, 可选): 配置选项

#### options 配置选项
- `onOpen`: (event) => void - 连接建立回调
- `onMessage`: (data) => void - 消息接收回调
- `onError`: (event) => void - 错误回调
- `onClose`: (event) => void - 连接关闭回调
- `reconnect`: boolean - 是否自动重连，默认 true
- `reconnectInterval`: number - 重连间隔(毫秒)，默认 3000
- `maxReconnectAttempts`: number - 最大重连次数，默认 5

#### 返回值
- `socket`: WebSocketInstance - WebSocket 实例
  - `connect()`: 连接 WebSocket
  - `disconnect()`: 断开连接
  - `send(data)`: 发送消息
  - `isConnected()`: 获取连接状态
  - `getReadyState()`: 获取连接状态码
- `isConnected`: Ref<boolean> - 连接状态响应式变量
- `lastMessage`: Ref<any> - 最后接收的消息响应式变量
- `error`: Ref<string|null> - 错误信息响应式变量

## 认证

WebSocket 连接会自动从 localStorage 中获取 `catchadmin_auth_token` 并作为 query 参数传递给服务器：

```
ws://localhost:8080?token=your-auth-token
```

## 注意事项

1. 组件卸载时会自动断开 WebSocket 连接
2. 支持自动重连机制，网络恢复后会自动重连
3. 发送的消息会自动序列化为 JSON 格式
4. 接收的消息会尝试解析为 JSON，解析失败时返回原始字符串
5. 连接失败或网络错误时会在控制台输出错误信息