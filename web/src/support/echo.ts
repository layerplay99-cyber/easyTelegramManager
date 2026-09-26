import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

window.Pusher = Pusher;

const useTLS = import.meta.env.VITE_REVERB_USE_TLS === 'true'

let echo: any;

try {
  // Laravel Reverb WebSocket 配置
  echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    cluster: 'us2',
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: useTLS,
    enabledTransports: useTLS ? ['wss', 'ws'] : ['ws'],
    disableStats: true,
    authEndpoint: `${import.meta.env.VITE_BASE_URL}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${localStorage.getItem('catchadmin_auth_token') || ''}`
      }
    }
  });

} catch (error) {
  console.error('Echo 初始化失败:', error);
  // 创建一个简单的 fallback 对象
  echo = {
    private: () => ({
      subscribed: () => {},
      error: () => {},
      listen: () => {}
    }),
    channel: () => ({
      listen: () => {}
    })
  };
}

export default echo;
