import { Tag } from 'antd'

const colorByStatus: Record<string, string> = {
  pending: 'gold',
  pending_payment: 'gold',
  pending_fulfillment: 'orange',
  submitted: 'gold',
  reviewing: 'gold',
  awaiting_return: 'blue',
  return_in_transit: 'cyan',
  partially_fulfilled: 'cyan',
  processing: 'purple',
  shipped: 'blue',
  delivered: 'cyan',
  approved: 'green',
  applied: 'green',
  succeeded: 'green',
  completed: 'green',
  active: 'green',
  rejected: 'red',
  failed: 'red',
  disabled: 'default',
  cancelled: 'default',
  closed: 'default',
  expired: 'default',
}

interface BusinessStatusTagProps {
  status: string
  label: string
}

export function BusinessStatusTag({ status, label }: BusinessStatusTagProps) {
  return <Tag color={colorByStatus[status] ?? 'default'}>{label}</Tag>
}
