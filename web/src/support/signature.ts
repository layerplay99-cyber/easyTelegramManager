/**
 * 将字符串转换为ArrayBuffer
 */
function stringToArrayBuffer(str: string): ArrayBuffer {
  return new TextEncoder().encode(str).buffer
}

/**
 * 将ArrayBuffer转换为十六进制字符串
 */
function arrayBufferToHex(buffer: ArrayBuffer): string {
  return Array.from(new Uint8Array(buffer))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('')
}

/**
 * 生成API签名（使用Web Crypto API）
 * @param data 请求数据（如果有的话）
 * @returns 返回签名相关的headers
 */
export async function generateApiSignature(data?: any) {
  // 从环境变量获取API_KEY
  const apiKey = (import.meta.env.VITE_API_KEY || '').trim()

  if (!apiKey) {
    console.error('API_KEY not found in environment variables')
    return {}
  }

  // 处理data参数
  let signatureData = ''
  if (data && Object.keys(data).length > 0) {
    // 如果data不为空，将其JSON字符串化
    signatureData = JSON.stringify(data)
  }

  try {
    // 将API Key转换为CryptoKey
    const keyData = stringToArrayBuffer(apiKey)
    const cryptoKey = await crypto.subtle.importKey(
      'raw',
      keyData,
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['sign']
    )

    // 生成HMAC-SHA256签名
    const dataBuffer = stringToArrayBuffer(signatureData)
    const signatureBuffer = await crypto.subtle.sign('HMAC', cryptoKey, dataBuffer)
    const signature = arrayBufferToHex(signatureBuffer)

    // 返回需要添加到请求头的签名信息
    return {
      'Api-Key': apiKey,
      'Api-Signature': signature
    }
  } catch (error) {
    console.error('Error generating signature:', error)
    return {}
  }
}

/**
 * 为HTTP请求添加签名头
 * @param request 请求对象
 * @param data 请求数据
 * @returns 添加了签名头的请求对象
 */
export async function addSignatureToRequest(request: any, data?: any) {
  const signatureHeaders = await generateApiSignature(data)

  // 添加签名头到请求
  if (request.headers) {
    request.headers = { ...request.headers, ...signatureHeaders }
  } else {
    request.headers = signatureHeaders
  }

  return request
}

/**
 * 验证签名是否正确（用于调试）
 * @param data 原始数据
 * @param receivedSignature 接收到的签名
 * @returns 是否匹配
 */
export async function verifySignature(data: any, receivedSignature: string): Promise<boolean> {
  const headers = await generateApiSignature(data)
  const generatedSignature = headers['Api-Signature']
  return generatedSignature === receivedSignature
}

/**
 * 使用示例：
 *
 * // 1. 为GET请求添加签名（无数据）
 * const getRequest = {
 *   url: '/api/users',
 *   method: 'GET'
 * }
 * const signedGetRequest = await addSignatureToRequest(getRequest)
 *
 * // 2. 为POST请求添加签名（有数据）
 * const postData = { name: 'John', email: 'john@example.com' }
 * const postRequest = {
 *   url: '/api/users',
 *   method: 'POST',
 *   data: postData
 * }
 * const signedPostRequest = await addSignatureToRequest(postRequest, postData)
 *
 * // 3. 在axios拦截器中使用
 * axios.interceptors.request.use(async (config) => {
 *   return await addSignatureToRequest(config, config.data)
 * })
 */
