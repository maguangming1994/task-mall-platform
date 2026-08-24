import { CloudUploadOutlined, DeleteOutlined, DownloadOutlined, LinkOutlined } from '@ant-design/icons'
import { Alert, Button, Input, Modal, Segmented, Space, Typography, Upload, message } from 'antd'
import { useState } from 'react'
import { ApiError, importMediaUrl, uploadMediaFile } from '../api/admin'

export interface ImageSourceValue {
  asset_id?: string
  url: string
}

interface Props {
  open: boolean
  onCancel: () => void
  onSelect: (image: ImageSourceValue) => void
  title?: string
}

type SourceMode = 'local' | 'import' | 'external'

const imageTypes = ['image/jpeg', 'image/png', 'image/webp']
const maxImageBytes = 10 * 1024 * 1024

function validHttpsUrl(value: string) {
  try {
    const parsed = new URL(value.trim())
    return parsed.protocol === 'https:' && !parsed.username && !parsed.password && value.trim().length <= 2048
  } catch {
    return false
  }
}

export function ImageSourceModal({ open, onCancel, onSelect, title = '添加图片' }: Props) {
  const [mode, setMode] = useState<SourceMode>('local')
  const [file, setFile] = useState<File | null>(null)
  const [url, setUrl] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [messageApi, contextHolder] = message.useMessage()

  const reset = () => {
    setMode('local')
    setFile(null)
    setUrl('')
    setSubmitting(false)
  }

  const close = () => {
    if (submitting) return
    reset()
    onCancel()
  }

  const selectFile = (candidate: File) => {
    if (!imageTypes.includes(candidate.type)) {
      messageApi.error('仅支持 JPG、PNG 和 WebP 图片。')
      return Upload.LIST_IGNORE
    }
    if (candidate.size > maxImageBytes) {
      messageApi.error('单张图片不能超过 10MB。')
      return Upload.LIST_IGNORE
    }
    setFile(candidate)
    return Upload.LIST_IGNORE
  }

  const submit = async () => {
    if (submitting) return
    const normalizedUrl = url.trim()
    if (mode === 'local' && !file) {
      messageApi.warning('请先选择一张图片。')
      return
    }
    if (mode !== 'local' && !validHttpsUrl(normalizedUrl)) {
      messageApi.warning('请填写不含账号信息的 HTTPS 图片地址。')
      return
    }

    try {
      setSubmitting(true)
      if (mode === 'external') {
        onSelect({ url: normalizedUrl })
        messageApi.success('已使用外部图片链接。')
      } else {
        const asset = mode === 'local'
          ? await uploadMediaFile(file as File)
          : await importMediaUrl(normalizedUrl)
        onSelect({ asset_id: asset.id, url: asset.url })
        messageApi.success(mode === 'local' ? '图片已上传到当前素材存储。' : '图片已下载并转存到当前素材存储。')
      }
      reset()
      onCancel()
    } catch (error) {
      messageApi.error(error instanceof ApiError ? error.message : '图片处理失败，请稍后重试。')
    } finally {
      setSubmitting(false)
    }
  }

  const submitDisabled = mode === 'local' ? !file : !validHttpsUrl(url)

  return <>
    {contextHolder}
    <Modal
      title={title}
      open={open}
      onCancel={close}
      onOk={() => void submit()}
      confirmLoading={submitting}
      closable={!submitting}
      keyboard={!submitting}
      maskClosable={!submitting}
      cancelButtonProps={{ disabled: submitting }}
      okButtonProps={{ disabled: submitting || submitDisabled }}
      okText={mode === 'local' ? '上传并使用' : mode === 'import' ? '下载转存并使用' : '直接使用链接'}
      cancelText="取消"
      destroyOnHidden
    >
      <Space direction="vertical" size="middle" style={{ width: '100%' }}>
        <Segmented<SourceMode>
          block
          value={mode}
          disabled={submitting}
          onChange={(value) => { setMode(value); setFile(null); setUrl('') }}
          options={[
            { value: 'local', label: '本地上传', icon: <CloudUploadOutlined /> },
            { value: 'import', label: '链接转存', icon: <DownloadOutlined /> },
            { value: 'external', label: '直接外链', icon: <LinkOutlined /> },
          ]}
        />

        {mode === 'local' && <>
          <Alert showIcon type="info" message="从电脑选择图片，系统会保存到当前启用的本地、腾讯云 COS 或七牛云 Kodo 存储。" />
          <Space direction="vertical" style={{ width: '100%' }}>
            <Upload accept="image/jpeg,image/png,image/webp" maxCount={1} showUploadList={false} beforeUpload={selectFile} disabled={submitting}>
              <Button icon={<CloudUploadOutlined />}>选择图片</Button>
            </Upload>
            {file && <Space style={{ width: '100%', justifyContent: 'space-between' }}>
              <Typography.Text ellipsis style={{ maxWidth: 380 }}>{file.name}</Typography.Text>
              <Button type="text" danger icon={<DeleteOutlined />} disabled={submitting} onClick={() => setFile(null)} aria-label="移除已选图片" />
            </Space>}
            <Typography.Text type="secondary">支持 JPG、PNG、WebP，单张不超过 10MB。</Typography.Text>
          </Space>
        </>}

        {mode === 'import' && <>
          <Alert showIcon type="info" message="系统会下载并校验远程图片，再保存到当前素材存储；原网站失效后也不影响本站图片。" />
          <Input
            value={url}
            disabled={submitting}
            onChange={(event) => setUrl(event.target.value)}
            placeholder="https://example.com/image.jpg"
            autoFocus
            onPressEnter={() => { if (!submitDisabled) void submit() }}
          />
        </>}

        {mode === 'external' && <>
          <Alert
            showIcon
            type="warning"
            message="外链不会转存"
            description="访客浏览时会直接请求第三方网站并可能暴露网络信息；源站失效、防盗链或微信小程序未配置该图片域名时，图片也可能无法显示。长期使用的图片建议选择“链接转存”。"
          />
          <Input
            value={url}
            disabled={submitting}
            onChange={(event) => setUrl(event.target.value)}
            placeholder="https://example.com/image.jpg"
            autoFocus
            onPressEnter={() => { if (!submitDisabled) void submit() }}
          />
        </>}
      </Space>
    </Modal>
  </>
}
