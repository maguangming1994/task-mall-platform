import { Tabs, Typography } from 'antd'
import { useState } from 'react'
import { DecorationEditorTab } from './DecorationEditorTab'
import { ReceiptTemplatePage } from './ReceiptTemplatePage'
import { SchemeListPage } from './SchemeListPage'

export function DecorationPage() {
  const [activeKey, setActiveKey] = useState('schemes')

  return <>
    <div className="page-heading">
      <div>
        <Typography.Text className="eyebrow">可视化页面配置</Typography.Text>
        <Typography.Title level={2}>装修中心</Typography.Title>
        <Typography.Paragraph type="secondary">
          页面方案支持同一页面保存多套装修并自行选择生效；小票模板可装修 58mm / 80mm 小票页面，绑定打印场景后由小票机打印。
        </Typography.Paragraph>
      </div>
    </div>
    <Tabs
      activeKey={activeKey}
      onChange={setActiveKey}
      items={[
        { key: 'schemes', label: '页面方案', children: <SchemeListPage onOpenLiveEditor={() => setActiveKey('editor')} /> },
        { key: 'editor', label: '页面装修', children: <DecorationEditorTab /> },
        { key: 'receipt', label: '小票模板', children: <ReceiptTemplatePage /> },
      ]}
    />
  </>
}
