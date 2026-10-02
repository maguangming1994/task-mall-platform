import { apiRequest } from '@/api/http'

interface UploadTicket {
  file_id: string
  upload_url: string
  method: 'PUT'
  headers: Record<string, string>
  status: string
}

interface PrivateFile {
  file_id: string
  status: 'issued' | 'uploaded' | 'scanning' | 'clean' | 'rejected' | 'expired'
  rejection_code: string | null
}

function chooseImage(): Promise<UniApp.ChooseImageSuccessCallbackResult> {
  return new Promise((resolve, reject) => uni.chooseImage({ count: 1, sizeType: ['compressed'], success: resolve, fail: reject }))
}

function readFile(path: string): Promise<ArrayBuffer> {
  // #ifdef H5
  return fetch(path).then((response) => response.arrayBuffer())
  // #endif
  // #ifndef H5
  return new Promise((resolve, reject) => {
    uni.getFileSystemManager().readFile({ filePath: path, success: (result) => resolve(result.data as ArrayBuffer), fail: reject })
  })
  // #endif
}

function putFile(url: string, data: ArrayBuffer, headers: Record<string, string>): Promise<void> {
  return new Promise((resolve, reject) => uni.request({
    url, method: 'PUT', data, header: headers,
    success: (response) => response.statusCode >= 200 && response.statusCode < 300 ? resolve() : reject(new Error(`上传失败（${response.statusCode}）`)),
    fail: reject,
  }))
}

function delay(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function detectImageFormat(data: ArrayBuffer) {
  const bytes = new Uint8Array(data)
  if (bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) {
    return { extension: 'jpg', contentType: 'image/jpeg' } as const
  }
  if (bytes.length >= 8 && bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47) {
    return { extension: 'png', contentType: 'image/png' } as const
  }
  if (bytes.length >= 12
    && bytes[0] === 0x52 && bytes[1] === 0x49 && bytes[2] === 0x46 && bytes[3] === 0x46
    && bytes[8] === 0x57 && bytes[9] === 0x45 && bytes[10] === 0x42 && bytes[11] === 0x50) {
    return { extension: 'webp', contentType: 'image/webp' } as const
  }

  throw new Error('头像仅支持 JPG、PNG 或 WebP 图片。')
}

export async function uploadAvatarFromPath(token: string, path: string): Promise<string> {
  const data = await readFile(path)
  const size = data.byteLength
  const { extension, contentType } = detectImageFormat(data)
  const ticket = await apiRequest<UploadTicket>({
    path: '/files/upload-tickets', method: 'POST', accessToken: token,
    headers: { 'Idempotency-Key': `avatar-${Date.now()}-${Math.random().toString(36).slice(2)}` },
    data: { filename: `avatar-${Date.now()}.${extension}`, content_type: contentType, size, purpose: 'avatar' },
  })
  await putFile(ticket.upload_url, data, ticket.headers)
  let file = await apiRequest<PrivateFile>({ path: `/files/${ticket.file_id}/complete`, method: 'POST', accessToken: token, data: {} })
  for (let attempt = 0; attempt < 10 && ['uploaded', 'scanning'].includes(file.status); attempt += 1) {
    await delay(800)
    file = await apiRequest<PrivateFile>({ path: `/files/${ticket.file_id}`, accessToken: token })
  }
  if (file.status !== 'clean') throw new Error(file.rejection_code ? `头像未通过安全检查：${file.rejection_code}` : '头像仍在安全检查中，请稍后重试。')

  return ticket.file_id
}

export async function chooseAndUploadAvatar(token: string): Promise<string> {
  const selected = await chooseImage()
  const path = selected.tempFilePaths[0]

  return uploadAvatarFromPath(token, path)
}

export async function chooseAndUploadTaskImage(token: string): Promise<string> {
  const selected = await chooseImage()
  const path = selected.tempFilePaths[0]
  const selectedFiles = Array.isArray(selected.tempFiles) ? selected.tempFiles : [selected.tempFiles]
  const size = selectedFiles[0]?.size ?? (await readFile(path)).byteLength
  const extension = /\.png(?:$|\?)/i.test(path) ? 'png' : 'jpg'
  const contentType = extension === 'png' ? 'image/png' : 'image/jpeg'
  const ticket = await apiRequest<UploadTicket>({
    path: '/files/upload-tickets', method: 'POST', accessToken: token,
    headers: { 'Idempotency-Key': `task-evidence-${Date.now()}-${Math.random().toString(36).slice(2)}` },
    data: { filename: `task-evidence-${Date.now()}.${extension}`, content_type: contentType, size, purpose: 'task_submission' },
  })
  await putFile(ticket.upload_url, await readFile(path), ticket.headers)
  let file = await apiRequest<PrivateFile>({ path: `/files/${ticket.file_id}/complete`, method: 'POST', accessToken: token, data: {} })
  for (let attempt = 0; attempt < 10 && ['uploaded', 'scanning'].includes(file.status); attempt += 1) {
    await delay(800)
    file = await apiRequest<PrivateFile>({ path: `/files/${ticket.file_id}`, accessToken: token })
  }
  if (file.status !== 'clean') throw new Error(file.rejection_code ? `附件未通过安全检查：${file.rejection_code}` : '附件仍在安全检查中，请稍后重试。')

  return ticket.file_id
}

export type PrivateImagePurpose = 'task_submission' | 'task_report' | 'refund_evidence' | 'return_shipping' | 'community' | 'second_hand' | 'offline_recharge'

export async function chooseAndUploadPrivateImage(token: string, purpose: PrivateImagePurpose): Promise<{ fileId: string; previewUrl: string }> {
  const selected = await chooseImage()
  const path = selected.tempFilePaths[0]
  const selectedFiles = Array.isArray(selected.tempFiles) ? selected.tempFiles : [selected.tempFiles]
  const data = await readFile(path)
  const size = selectedFiles[0]?.size ?? data.byteLength
  const extension = /\.png(?:$|\?)/i.test(path) ? 'png' : 'jpg'
  const contentType = extension === 'png' ? 'image/png' : 'image/jpeg'
  const ticket = await apiRequest<UploadTicket>({
    path: '/files/upload-tickets', method: 'POST', accessToken: token,
    headers: { 'Idempotency-Key': `${purpose}-${Date.now()}-${Math.random().toString(36).slice(2)}` },
    data: { filename: `${purpose}-${Date.now()}.${extension}`, content_type: contentType, size, purpose },
  })
  await putFile(ticket.upload_url, data, ticket.headers)
  let file = await apiRequest<PrivateFile>({ path: `/files/${ticket.file_id}/complete`, method: 'POST', accessToken: token, data: {} })
  for (let attempt = 0; attempt < 10 && ['uploaded', 'scanning'].includes(file.status); attempt += 1) {
    await delay(800)
    file = await apiRequest<PrivateFile>({ path: `/files/${ticket.file_id}`, accessToken: token })
  }
  if (file.status !== 'clean') throw new Error(file.rejection_code ? `图片未通过安全检查：${file.rejection_code}` : '图片仍在安全检查中，请稍后重试。')

  return { fileId: ticket.file_id, previewUrl: path }
}
