import {
  AlignCenterOutlined, AlignLeftOutlined, AlignRightOutlined, BoldOutlined, ClearOutlined,
  ItalicOutlined, LinkOutlined, OrderedListOutlined, PictureOutlined, RedoOutlined,
  StrikethroughOutlined, TableOutlined, UnderlineOutlined, UndoOutlined, UnorderedListOutlined,
} from '@ant-design/icons'
import Image from '@tiptap/extension-image'
import Link from '@tiptap/extension-link'
import Placeholder from '@tiptap/extension-placeholder'
import { Table } from '@tiptap/extension-table'
import TableCell from '@tiptap/extension-table-cell'
import TableHeader from '@tiptap/extension-table-header'
import TableRow from '@tiptap/extension-table-row'
import TextAlign from '@tiptap/extension-text-align'
import Underline from '@tiptap/extension-underline'
import { EditorContent, useEditor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { Button, Input, Modal, Select, Space, Tooltip } from 'antd'
import { useEffect, useState, type ReactNode } from 'react'

interface RichTextEditorProps {
  value?: string
  onChange?: (html: string) => void
  placeholder?: string
  minHeight?: number
  disabled?: boolean
  compact?: boolean
}

type InsertMode = 'link' | 'image' | null

export function RichTextEditor({ value = '', onChange, placeholder = '请输入内容…', minHeight = 240, disabled = false, compact = false }: RichTextEditorProps) {
  const [insertMode, setInsertMode] = useState<InsertMode>(null)
  const [resourceUrl, setResourceUrl] = useState('')
  const editor = useEditor({
    editable: !disabled,
    extensions: [
      StarterKit.configure({ link: false, underline: false }),
      Underline,
      Link.configure({ openOnClick: false, autolink: true, defaultProtocol: 'https', protocols: ['http', 'https', 'mailto'] }),
      Image.configure({ allowBase64: false, inline: false }),
      TextAlign.configure({ types: ['heading', 'paragraph'] }),
      Placeholder.configure({ placeholder }),
      Table.configure({ resizable: true }), TableRow, TableHeader, TableCell,
    ],
    content: value,
    onUpdate: ({ editor: currentEditor }) => onChange?.(currentEditor.isEmpty ? '' : currentEditor.getHTML()),
    editorProps: { attributes: { class: 'rich-text-editor-content', style: `min-height:${minHeight}px` } },
  })

  useEffect(() => {
    if (!editor || editor.getHTML() === value) return
    editor.commands.setContent(value || '', { emitUpdate: false })
  }, [editor, value])

  useEffect(() => { editor?.setEditable(!disabled) }, [disabled, editor])

  if (!editor) return null

  const toolbarButton = (label: string, icon: ReactNode, action: () => void, active = false, actionDisabled = false) => (
    <Tooltip title={label}>
      <Button type={active ? 'primary' : 'text'} icon={icon} aria-label={label} disabled={disabled || actionDisabled} onClick={action} />
    </Tooltip>
  )

  const openInsert = (mode: Exclude<InsertMode, null>) => {
    setResourceUrl(mode === 'link' ? (editor.getAttributes('link').href ?? '') : '')
    setInsertMode(mode)
  }

  const confirmInsert = () => {
    const url = resourceUrl.trim()
    if (insertMode === 'link') {
      if (!url) editor.chain().focus().unsetLink().run()
      else editor.chain().focus().extendMarkRange('link').setLink({ href: url, target: '_blank', rel: 'noopener noreferrer nofollow' }).run()
    }
    if (insertMode === 'image' && url) editor.chain().focus().setImage({ src: url }).run()
    setInsertMode(null)
    setResourceUrl('')
  }

  return (
    <div className={`rich-text-editor${disabled ? ' is-disabled' : ''}`}>
      <div className="rich-text-editor-toolbar">
        {!compact && <Select
          value={editor.isActive('heading', { level: 2 }) ? 'h2' : editor.isActive('heading', { level: 3 }) ? 'h3' : 'paragraph'}
          disabled={disabled}
          style={{ width: 104 }}
          options={[{ value: 'paragraph', label: '正文' }, { value: 'h2', label: '二级标题' }, { value: 'h3', label: '三级标题' }]}
          onChange={(type) => type === 'paragraph' ? editor.chain().focus().setParagraph().run() : editor.chain().focus().toggleHeading({ level: type === 'h2' ? 2 : 3 }).run()}
        />}
        <Space size={2} wrap>
          {toolbarButton('加粗', <BoldOutlined />, () => editor.chain().focus().toggleBold().run(), editor.isActive('bold'))}
          {toolbarButton('斜体', <ItalicOutlined />, () => editor.chain().focus().toggleItalic().run(), editor.isActive('italic'))}
          {toolbarButton('下划线', <UnderlineOutlined />, () => editor.chain().focus().toggleUnderline().run(), editor.isActive('underline'))}
          {toolbarButton('删除线', <StrikethroughOutlined />, () => editor.chain().focus().toggleStrike().run(), editor.isActive('strike'))}
          {toolbarButton('无序列表', <UnorderedListOutlined />, () => editor.chain().focus().toggleBulletList().run(), editor.isActive('bulletList'))}
          {toolbarButton('有序列表', <OrderedListOutlined />, () => editor.chain().focus().toggleOrderedList().run(), editor.isActive('orderedList'))}
          {toolbarButton('左对齐', <AlignLeftOutlined />, () => editor.chain().focus().setTextAlign('left').run(), editor.isActive({ textAlign: 'left' }))}
          {toolbarButton('居中', <AlignCenterOutlined />, () => editor.chain().focus().setTextAlign('center').run(), editor.isActive({ textAlign: 'center' }))}
          {toolbarButton('右对齐', <AlignRightOutlined />, () => editor.chain().focus().setTextAlign('right').run(), editor.isActive({ textAlign: 'right' }))}
          {toolbarButton('插入链接', <LinkOutlined />, () => openInsert('link'), editor.isActive('link'))}
          {!compact && toolbarButton('插入网络图片', <PictureOutlined />, () => openInsert('image'))}
          {!compact && toolbarButton('插入表格', <TableOutlined />, () => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run())}
          {toolbarButton('撤销', <UndoOutlined />, () => editor.chain().focus().undo().run(), false, !editor.can().undo())}
          {toolbarButton('重做', <RedoOutlined />, () => editor.chain().focus().redo().run(), false, !editor.can().redo())}
          {toolbarButton('清除格式', <ClearOutlined />, () => editor.chain().focus().unsetAllMarks().clearNodes().run())}
        </Space>
      </div>
      <EditorContent editor={editor} />
      <div className="rich-text-editor-hint">{compact ? '支持加粗、斜体、下划线、列表、对齐与链接；粘贴内容会统一为平台样式。' : '支持标题、列表、链接、HTTPS 图片与表格；粘贴内容会统一为平台样式。'}</div>
      <Modal title={insertMode === 'image' ? '插入网络图片' : '插入链接'} open={insertMode !== null} onCancel={() => setInsertMode(null)} onOk={confirmInsert} okButtonProps={{ disabled: insertMode === 'image' && !resourceUrl.trim() }} okText="插入" cancelText="取消" destroyOnHidden>
        <Input value={resourceUrl} onChange={(event) => setResourceUrl(event.target.value)} placeholder={insertMode === 'image' ? 'https://example.com/image.jpg' : 'https://example.com'} autoFocus onPressEnter={confirmInsert} />
      </Modal>
    </div>
  )
}
