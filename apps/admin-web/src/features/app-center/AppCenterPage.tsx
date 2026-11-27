import { useMemo, useState, type CSSProperties, type ReactNode } from 'react'
import { Empty, Input } from 'antd'
import { ArrowRightOutlined, SearchOutlined } from '@ant-design/icons'
import { useAdminAuth } from '../auth/AdminAuthContext'
import { navigate, visibleGroups } from '../../routes'

function GroupBadge({ accent, icon }: { accent: string; icon: ReactNode }) {
  return (
    <span className="xq-apps-group-badge" style={{ background: accent }}>
      {icon}
    </span>
  )
}

export function AppCenterPage() {
  const { navigation } = useAdminAuth()
  const [keyword, setKeyword] = useState('')

  const groups = useMemo(
    () => visibleGroups(navigation).filter((group) => group.key !== 'apps'),
    [navigation],
  )

  const query = keyword.trim().toLowerCase()
  const filtered = useMemo(
    () => groups
      .map((group) => ({
        ...group,
        pages: query === ''
          ? group.pages
          : group.pages.filter((page) =>
            [page.title, page.description, group.label]
              .some((text) => text.toLowerCase().includes(query))),
      }))
      .filter((group) => group.pages.length > 0),
    [groups, query],
  )

  const total = groups.reduce((sum, group) => sum + group.pages.length, 0)
  const matched = filtered.reduce((sum, group) => sum + group.pages.length, 0)

  return (
    <div className="xq-page xq-apps">
      <div className="xq-apps-head">
        <div className="xq-apps-title">
          <h2>应用中心</h2>
          <p>全部模块与功能入口集中在此，按业务分类快速进入，不必逐层展开左侧导航。</p>
          <div className="xq-apps-chips">
            <span>{groups.length} 个业务分组</span>
            <span>{total} 个功能入口</span>
            {query !== '' && <span className="match">匹配 {matched} 个</span>}
          </div>
        </div>
        <Input
          allowClear
          className="xq-apps-search"
          value={keyword}
          onChange={(event) => setKeyword(event.target.value)}
          prefix={<SearchOutlined />}
          placeholder="搜索模块或功能入口"
          aria-label="搜索模块或功能入口"
        />
      </div>

      {filtered.length === 0 && (
        <div className="xq-card">
          <Empty description={`没有找到与「${keyword.trim()}」相关的入口`} />
        </div>
      )}

      {filtered.map((group) => (
        <section
          className="xq-apps-group"
          key={group.key}
          style={{ '--apps-accent': group.accent } as CSSProperties}
        >
          <div className="xq-apps-group-head">
            <GroupBadge accent={group.accent} icon={group.icon} />
            <h3>{group.label}</h3>
            <span className="count">{group.pages.length}</span>
            <i className="rule" />
          </div>
          <div className="xq-apps-grid">
            {group.pages.map((page) => (
              <button
                type="button"
                className="xq-apps-card"
                key={page.path}
                onClick={() => navigate(page.path)}
              >
                <span
                  className="xq-apps-icon"
                  style={{ color: group.accent, background: `${group.accent}14` }}
                >
                  {page.icon}
                </span>
                <span className="xq-apps-body">
                  <b>{page.title}</b>
                  <p>{page.description}</p>
                </span>
                <ArrowRightOutlined className="xq-apps-arrow" />
              </button>
            ))}
          </div>
        </section>
      ))}
    </div>
  )
}
