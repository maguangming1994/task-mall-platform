export interface AdminProfile {
  id: string
  account: string
  display_name: string
  email: string | null
  status: string
  requires_mfa: boolean
  roles: string[]
  permissions: string[]
}

export interface NavigationItem {
  code: string
  label: string
  icon: string
  path: string
  permissions: string[]
}

export interface AdminTokens {
  access_token: string
  refresh_token: string
  token_type: 'Bearer'
  expires_in: number
  admin: AdminProfile
  navigation: NavigationItem[]
}

export interface AdminMfaChallenge {
  mfa_required: true
  challenge_id: string
  challenge_token: string
  expires_at: string
}

export interface ManagedAdmin extends AdminProfile {
  active_sessions_count: number
  last_login_at: string | null
}

export interface AdminRole {
  code: string
  name: string
  description: string | null
  system_role: boolean
  permissions: string[]
  permission_items: Array<{ code: string; name: string; group: string }>
}
export interface AdminPermission { code: string; name: string; group: string; description: string | null }

export type SettingValue = boolean | number | string | string[] | Record<string, unknown>

export interface SettingDraft {
  value: SettingValue
  reason: string
  review_status: 'not_required' | 'pending' | 'approved' | 'rejected'
  base_version: number | null
  rollback_source_version: number | null
  reviewed_by: string | null
  review_comment: string | null
  reviewed_at: string | null
  lock_version: number
  updated_at: string
}

export interface SystemSetting {
  key: string
  group: string
  name: string
  description: string | null
  value_type: 'boolean' | 'integer' | 'money' | 'decimal' | 'string' | 'time' | 'enum' | 'multi_enum' | 'object'
  unit: string | null
  options: string[] | null
  validation_rules: Record<string, unknown> | null
  risk_level: 'normal' | 'high'
  current_value: SettingValue
  current_version: number | null
  current_source: 'default' | 'published'
  draft: SettingDraft | null
  diff: { before: SettingValue; after: SettingValue; changed: boolean } | null
}

export interface SettingVersion {
  version: number
  value: SettingValue
  source: 'publish' | 'rollback'
  source_version: number | null
  reason: string
  published_at: string
}

export interface PrivacyRequestItem {
  id: string
  request_type: 'access' | 'copy' | 'correction' | 'deletion' | 'withdraw_consent'
  scope: string[]
  description: string | null
  status: 'submitted' | 'verifying' | 'processing' | 'completed' | 'partially_completed' | 'rejected'
  blockers: string[]
  result_file_id: string | null
  result_expires_at: string | null
  requested_at: string
  completed_at: string | null
  resolution_note: string | null
  user: { id: string; nickname: string; status: string }
}
export interface ManagedLegalDocument {
  id: string; document_type: string; version: string; title: string; content_format: 'markdown' | 'rich_text_html'; content: string;
  content_hash: string; effective_at: string; current: boolean; consent_required: boolean; withdrawal_allowed: boolean;
  change_summary: string | null; status: 'draft' | 'published'; published_at: string | null
}

export interface ProductionReadiness {
  ready: boolean
  checked_at: string
  checks: Array<{ code: string; label: string; ready: boolean; action: string | null }>
  summary: { total: number; passed: number; failed: number }
}

export interface RewardBudgetPool {
  id: string
  name: string
  asset_type: 'points' | 'coupon' | 'wallet_cash'
  coupon_template_id?: string | null
  total_amount: number
  locked_amount: number
  issued_amount: number
  available_amount: number
  status: string
  version: number
}

export interface ManagedCouponTemplate {
  id: string
  name: string
  cash_discount_cents: number
  points_discount: number
  minimum_cash_cents: number
  minimum_points: number
  valid_days: number
  total_quantity: number
  issued_quantity: number
  remaining_quantity: number
  status: 'active' | 'inactive'
  version: number
  created_at: string | null
}

export type TaskStepKind = 'instruction' | 'link' | 'qr_code' | 'copy_text' | 'image_guide' | 'verification_image' | 'form_input' | 'confirmation'
export interface ManagedTaskStep {
  id: string
  kind: TaskStepKind
  title: string
  description?: string | null
  input_type: 'none' | 'text' | 'image' | 'text_image' | 'link'
  required: boolean
  link_url?: string | null
  copy_text?: string | null
  example_image_url?: string | null
  button_text?: string | null
  placeholder?: string | null
  min_files?: number
  max_files?: number
}

export interface ManagedTask {
  id: string
  business_scenario: string
  title: string
  description: string
  category: string
  tags: string[]
  task_type: string
  project_name?: string | null
  cover_image_url?: string | null
  supported_devices?: string[]
  steps: ManagedTaskStep[]
  visibility: string
  participation_mode: string
  verification_mode: string
  rewards: Array<{ type: string; points?: number; balance_amount?: number; coupon_template_id?: string; quantity?: number }>
  reward_budget_bindings: Array<{ reward_sequence: number; budget_pool_id: string }>
  reward_quota_total: number
  reward_quota_per_cycle?: number | null
  progress_target?: number
  progress_event_type?: string | null
  data_batch?: { id: string; name: string; snapshot_hash: string; field_mapping: Record<string, string>; item_count: number } | null
  virtual_view_count?: number
  completion_window_hours: number
  submission_limit: number
  review_deadline_hours: number
  appeal_deadline_days: number
  appeal_limit: number
  attachment_rules: Record<string, number>
  claim_starts_at: string
  claim_ends_at: string
  submit_ends_at: string
  rules: string[]
  status: string
  version: number
  current_published_version?: number | null
  has_unpublished_changes?: boolean
  task_version: { version_no: number; change_type: string; status: string }
  publish_approval: null | {
    id: string
    status: 'pending' | 'approved' | 'rejected' | 'withdrawn'
    triggered_rules: string[]
    threshold_snapshot: Record<string, number>
    requested_by_admin_id: string
    reviewed_by_admin_id: string | null
    review_note: string | null
    reviewed_at: string | null
  }
  reward_budget_summaries: Array<{ asset_type: string; locked_amount: number; available_amount: number }>
}

export interface ManagedTaskVersionSummary {
  version_no: number
  change_type: string
  source_version_no: number | null
  status: string
  changed_fields: string[]
  created_at: string | null
  published_at: string | null
}

export interface ManagedTaskSubmission {
  id: string
  task_claim_id: string
  user_id: string
  task_title: string
  task_steps: ManagedTaskStep[]
  submission_no: number
  status: 'submitted' | 'reviewing' | 'approved' | 'rejected' | 'withdrawn'
  execution_status?: string | null
  execution_status_label?: string | null
  review_status?: string | null
  review_status_label?: string | null
  reviewer?: { id: string; display_name: string } | null
  review_assigned_at?: string | null
  review_lock_expires_at?: string | null
  revision_step_ids?: string[]
  supersedes_submission_id?: string | null
  latest_review?: {
    decision: 'approve' | 'reject'
    reason_code: string | null
    note: string | null
    reviewed_at: string | null
    step_results: Array<{ step_id: string; step_title: string; decision: 'approve' | 'reject'; reason_code: string | null; note: string | null; file_ids: string[] }>
  } | null
  content: { text?: string | null; fields?: Record<string, unknown> }
  file_ids: string[]
  submitted_at: string
  review_due_at: string
  overdue: boolean
}

export interface TaskReviewReason { id: string; code: string; label: string; suggested_note: string | null; sort_order: number }

export interface TaskDataBatch {
  id: string
  name: string
  original_filename: string
  source_format: 'csv'
  source_headers: string[]
  field_mapping: Record<string, string>
  row_count: number
  valid_count: number
  invalid_count: number
  available_count: number
  allocated_count: number
  status: 'ready' | 'preflight_failed' | 'locked'
  snapshot_hash: string
  locked_at: string | null
  preview?: Array<{ row_no: number; mapped_payload: Record<string, string>; validation_errors: string[]; status: string }> | null
}

export interface ManagedTaskRewardDelivery {
  id: string
  claim_id: string
  user_id: string
  task_title: string
  asset_type: 'points' | 'wallet_cash' | 'coupon'
  amount: number
  reward_snapshot: Record<string, unknown>
  status: 'pending' | 'failed' | 'delivered'
  attempt_count: number
  last_error_code: string | null
  last_error_message: string | null
  last_attempted_at: string | null
  next_retry_at: string | null
  delivered_at: string | null
}

export interface ManagedTaskAppeal { id: string; claim_id: string; appeal_no: number; status: string; reason: string; result_reason: string | null; created_at: string }
export interface ManagedTaskRiskCase { id: string; task_id: string; source: string; risk_level: string; status: string; reward_frozen: boolean; conclusion: string | null; response_due_at: string; overdue: boolean }
export type ProductPurchaseMode = 'cash' | 'points' | 'cash_points'
export interface ManagedPurchaseOption { id: string; purchase_mode: ProductPurchaseMode; cash_amount: number; points: number; enabled: boolean; channels: string[]; version: number }
export interface ManagedProductSku { id: string; sku_code: string; name: string; specs: Record<string, string>; fulfillment_type: 'logistics' | 'no_logistics' | 'virtual_delivery'; shipping_template_id: string | null; stock: number; status: string; purchase_options: ManagedPurchaseOption[] }
export interface ManagedProduct { id: string; name: string; subtitle: string | null; description: string; gallery_images: Array<{ asset_id?: string; url: string; alt?: string | null; sort_order: number }>; status: 'draft' | 'on_sale' | 'off_shelf'; version: number; definition_version: number; skus: ManagedProductSku[] }
export type ManagedShippingChargeType = 'fixed' | 'by_quantity' | 'by_weight'
export type ManagedShippingFreeRuleType = 'product' | 'sku' | 'cash_threshold' | 'quantity_threshold'
export interface ManagedShippingRegion {
  region_codes: string[]
  deliverable: boolean
  remote_surcharge_amount: number
}
export interface ManagedShippingFreeRule {
  type: ManagedShippingFreeRuleType
  target_ids?: string[]
  threshold_amount?: number
  threshold_quantity?: number
}
export interface ManagedShippingTemplateInput {
  name: string
  charge_type: ManagedShippingChargeType
  fixed_amount?: number | null
  first_unit?: number | null
  first_amount?: number | null
  additional_unit?: number | null
  additional_amount?: number | null
  regions: ManagedShippingRegion[]
  free_rules: ManagedShippingFreeRule[]
  enabled: boolean
}
export interface ManagedShippingTemplate extends ManagedShippingTemplateInput {
  id: string
  status: 'active' | 'inactive' | 'draft'
  version: number
  definition_version: number
}
export interface ManagedInventory { sku_id: string; sku_code: string; product_id: string; product_name: string; on_hand_quantity: number; reserved_quantity: number; available_quantity: number; low_stock_threshold: number; low_stock: boolean; version: number }
export interface MediaAsset { id: string; url: string; name: string; mime_type: string; size_bytes: number; provider: string; status: string }
export interface MediaStorageProvider {
  provider: 'local' | 'tencent_cos' | 'qiniu_kodo'; display_name: string; enabled: boolean; active: boolean;
  endpoint: string | null; region: string | null; bucket: string | null; private_bucket: string | null; public_base_url: string | null;
  access_key_configured: boolean; access_key_masked: string | null; secret_key_configured: boolean;
  last_tested_at: string | null; last_test_status: 'success' | 'failed' | null; last_test_message: string | null
}
export interface MediaStorageMigration {
  id: string; from_provider: string; to_provider: string; status: 'pending' | 'running' | 'completed' | 'completed_with_errors' | 'failed';
  total_assets: number; processed_assets: number; failed_assets: number; delete_source: boolean;
  errors: Array<{ asset_id?: string; message: string }>; created_at: string; completed_at: string | null
}

export type SmsServiceDriver = 'aliyun' | 'webhook' | 'log'
export type SmsSecretField = 'access_key_id' | 'access_key_secret' | 'security_token'

export interface SmsSecretState {
  configured: boolean
  masked: string | null
}

export interface SmsServiceSettings {
  driver: SmsServiceDriver
  access_key_id: SmsSecretState
  access_key_secret: SmsSecretState
  security_token: SmsSecretState
  region: string | null
  endpoint: string | null
  sign_name: string | null
  template_code: string | null
  verification_variables: string[]
  timeout_seconds: number
  ttl_seconds: number
  resend_interval_seconds: number
  hourly_limit: number
  daily_limit: number
  max_attempts: number
  last_tested_at: string | null
  last_test_status: 'success' | 'failed' | null
  last_test_message: string | null
}

export interface SmsServiceSettingsInput {
  reason: string
  driver: SmsServiceDriver
  region: string | null
  endpoint: string | null
  sign_name: string | null
  template_code: string | null
  verification_variables: string[]
  timeout_seconds: number
  ttl_seconds: number
  resend_interval_seconds: number
  hourly_limit: number
  daily_limit: number
  max_attempts: number
  access_key_id?: string
  access_key_secret?: string
  security_token?: string
  clear_access_key_id?: boolean
  clear_access_key_secret?: boolean
  clear_security_token?: boolean
}

export interface SmsServiceTestResult {
  accepted: boolean
  message: string
  request_id?: string
  sent_at?: string | null
}

export type WechatDriver = 'local' | 'wechat'
export type SecureValueState = { configured: boolean; masked: string | null; source: 'backend' | 'env' | 'none' }
export interface WechatAppSettings {
  driver: WechatDriver
  app_id: string
  app_id_source: 'backend' | 'env'
  app_secret: SecureValueState
  token: SecureValueState
  encoding_aes_key: SecureValueState
  timeout_seconds: number
  ready: boolean
  last_tested_at: string | null
  last_test_status: 'success' | 'failed' | null
  last_test_message: string | null
}
export interface WechatVerificationFile { filename: string; public_url: string; updated_at: string | null }
export interface WechatSettingsBundle {
  mini: WechatAppSettings
  official_account: WechatAppSettings
  verification_files: WechatVerificationFile[]
}
export interface WechatPaymentSettings {
  driver: WechatDriver
  merchant_id: string
  merchant_certificate_serial: string
  notify_url: string
  refund_notify_url: string
  timeout_seconds: number
  api_v3_key: SecureValueState
  merchant_private_key: SecureValueState
  platform_certificate: SecureValueState
  ready: boolean
  last_tested_at: string | null
  last_test_status: 'success' | 'failed' | null
  last_test_message: string | null
}
export interface IdentityVerificationSettings {
  driver: 'disabled' | 'manual' | 'aliyun'
  collection_enabled: boolean
  manual_review_enabled: boolean
  aliyun: { region: string; endpoint: string; timeout_seconds: number }
  access_key_id: SecureValueState
  access_key_secret: SecureValueState
  security_token: SecureValueState
  ready: boolean
  source: string
}

export interface UserAssetSummary {
  user_id: string
  points: { available: number; frozen: number; debt: number }
  wallet: { available_amount: number; frozen_amount: number; currency: 'CNY' }
  coupons: { available: number }
  version: number
}

export interface AssetAdjustment {
  id: string
  asset_type: 'points' | 'wallet' | 'coupon'
  action: 'increase' | 'decrease' | 'clear' | 'issue'
  amount: number | null
  status: 'pending' | 'approved' | 'rejected' | 'applied'
  requires_review: boolean
  reason: string
  review_note: string | null
  reviewed_at: string | null
  applied_at: string | null
  created_at: string
}

export interface WalletWithdrawal {
  id: string
  withdrawal_no: string
  requested_amount: number
  fee_rate: number
  fee_amount: number
  received_amount: number
  channel: string
  status: string
  review_note: string | null
  created_at: string
}

export interface ReconciliationEntry { id: string; payment_no: string; provider_trade_no: string | null; provider_amount: number | null; local_amount: number | null; provider_state: string | null; local_state: string | null; result: string; resolution_status: string }
export interface ReconciliationRun { id: string; provider: string; business_date: string; status: string; provider_count: number; provider_amount: number; local_count: number; local_amount: number; matched_count: number; difference_count: number; entries: ReconciliationEntry[]; completed_at: string | null }
export interface ManagedAfterSaleReason { id: string; code: string; name: string; service_types: string[]; default_responsibility: string; return_shipping_payer: string; evidence_required: boolean; enabled: boolean; sort_order: number; version: number }
export interface ReturnShippingCompensation { id: string; source_type: string; requested_amount: number; approved_amount: number; status: string; carrier_code: string; tracking_no: string; payment_proof_file_id: string; review_note: string | null; paid_at: string | null; created_at: string }
export interface AfterSaleShipment { id?: string; direction?: string; method?: string; carrier_code?: string | null; tracking_no?: string | null; no_logistics_note?: string | null; paid_shipping_amount?: number; status?: string; shipped_at?: string | null; received_at?: string | null; compensation?: ReturnShippingCompensation | null }
export interface ManagedRefund { id: string; refund_no: string; service_type: string; reason_name: string; status: string; refund_cash_amount: number; refund_points: number; responsibility_party: string; return_shipping_payer: string; return_method: string | null; review_note?: string | null; user_action_deadline_at: string | null; return_shipment?: AfterSaleShipment | null; compensation?: ReturnShippingCompensation | null; created_at: string }
export interface ManagedExchange { id: string; exchange_no: string; reason_name: string; status: string; quantity: number; responsibility_party: string; return_shipping_payer: string; replacement_shipping_payer: string; return_method: string; review_note?: string | null; return_deadline_at: string | null; replacement_auto_complete_at?: string | null; shipments?: AfterSaleShipment[]; created_at: string }
export interface ManagedCommunityPost { id: string; author: { id: string; nickname: string }; title: string; content: string; status: string; current_version_no: number; published_version_no: number | null; comment_count: number; reward_granted: boolean; created_at: string }
export interface ManagedCommunityComment { id: string; post_id: string; parent_comment_id: string | null; author: { id: string; nickname: string }; content: string; status: string; reward_granted: boolean; created_at: string }
export interface ManagedContentReport { id: string; content_type: 'post' | 'comment'; content_id: string; reason_code: string; description: string | null; status: string; created_at: string }
export interface ManagedContentAppeal { id: string; action_id: string; reason: string; status: string; review_note: string | null; created_at: string }
export type CommunityActivityStatus = 'pending_review' | 'approved' | 'rejected' | 'closed'
export interface ManagedCommunityActivityParticipant {
  participant_id: number | null
  user_id: string | null
  nickname: string
  role: 'creator' | 'participant'
  status: 'joined' | 'left' | 'removed'
  joined_at: string | null
  left_at: string | null
}

export interface WalletRecharge {
  id: string
  recharge_no: string
  user_id: string
  method: 'card_code' | 'online' | 'offline'
  amount_cents: number
  status: string
  payer_name: string | null
  offline_reference: string | null
  note: string | null
  offline_proof_file_ids: string[]
  review_note: string | null
  created_at: string
}

export interface WalletRechargeCard {
  id: string
  code_mask: string
  code?: string
  amount_cents: number
  status: string
  expires_at: string | null
  redeemed_at: string | null
  created_at: string
}
export interface ManagedCommunityActivity {
  id: string
  category_code: string
  category_label: string
  title: string
  details: string
  images: Array<{ file_id: string; url: string | null }>
  location: string
  starts_at: string
  participant_limit: number
  participant_count: number
  participants?: ManagedCommunityActivityParticipant[]
  remaining_slots: number
  status: CommunityActivityStatus
  joined_by_me: boolean
  is_creator: boolean
  can_join: boolean
  creator: { id: string | null; nickname: string }
  review_reason_code?: string | null
  review_note?: string | null
  reviewed_at?: string | null
  created_at: string
}

export interface ManagedTaskTemplate {
  id: string
  code: string
  name: string
  description: string | null
  category: string | null
  status: 'active' | 'inactive'
  status_label: string
  version: number
  current_version_no: number
  copied_from_template_id: string | null
  content?: Record<string, any>
  created_at: string
  updated_at: string
}

export interface ManagedTaskTemplateVersion {
  version_no: number
  source_version_no: number | null
  changed_fields: string[]
  content: Record<string, any>
  created_at: string
}
export type CommunityHomepageLinkType = 'posts' | 'activities' | 'tasks' | 'none'
export type CommunityQuickTarget = 'publish_post' | 'activities' | 'tasks' | 'posts' | 'notifications'
export interface CommunityHomepageBanner {
  title: string
  subtitle: string
  image_url?: string | null
  link_type: CommunityHomepageLinkType
  accent_color?: string | null
}
export interface CommunityHomepageQuickEntry {
  id: string
  label: string
  description?: string | null
  icon_url?: string | null
  target: CommunityQuickTarget
  enabled: boolean
  color?: string | null
  sort_order?: number
}
export interface CommunityHomepageHotTopic {
  id: string
  label: string
  target: string
  enabled: boolean
  color?: string | null
}
export interface CommunityHomepageConfig {
  banner: CommunityHomepageBanner
  quick_entries: CommunityHomepageQuickEntry[]
  hot_topics: CommunityHomepageHotTopic[]
  version: number | null
}
export interface CommunityHomepageConfigInput {
  banner: CommunityHomepageBanner
  quick_entries: CommunityHomepageQuickEntry[]
  hot_topics: CommunityHomepageHotTopic[]
  expected_version?: number | null
  reason: string
}
export interface OperationsMetrics { users: { active_total: number; new_today: number }; tasks: { published: number; pending_reviews: number }; orders: { paid_today: number; paid_cash_today: number; pending_fulfillment: number }; after_sales: { pending_refunds: number; pending_exchanges: number }; community: { published_posts: number; pending_reviews: number; open_reports: number }; finance: { reconciliation_differences: number; pending_withdrawals: number }; notifications: { unread_total: number; failed_deliveries: number; retrying_deliveries: number }; generated_at: string }

export interface ManagedOrderItem {
  id: string
  product_name: string
  sku_name: string
  specs: Record<string, string | number>
  quantity: number
  refunded_quantity: number
  pending_refund_quantity: number
  active_exchange_quantity: number
  remaining_after_sale_quantity: number
  fulfilled_quantity: number
  remaining_fulfillment_quantity: number
  purchase_mode: string
  fulfillment_type: string
  unit_cash_amount: number
  unit_points: number
  paid_cash_amount: number
  paid_points: number
}

export interface ManagedOrderFulfillment {
  id: string
  fulfillment_no: string
  method: string
  status: string
  carrier_name: string | null
  tracking_no: string | null
  no_logistics_reason: string | null
  fulfilled_at: string | null
}

export interface ManagedOrder {
  id: string
  order_no: string
  status: string
  fulfillment_type: string
  cash_payment_method: string
  payable_cash_amount: number
  payable_points: number
  paid_wallet_amount: number
  paid_external_amount: number
  paid_points: number
  refunded_wallet_amount: number
  refunded_external_amount: number
  refunded_points: number
  shipping_amount: number
  user: { id: string; nickname: string; phone_masked: string | null }
  items: ManagedOrderItem[]
  fulfillments: ManagedOrderFulfillment[]
  address_snapshot?: Record<string, unknown> | null
  status_changes?: Array<{ from_status: string | null; to_status: string; event: string; reason: string; created_at: string }>
  created_at: string
  paid_at: string | null
  fulfill_by: string | null
  shipped_at?: string | null
  completed_at?: string | null
}

export interface AdminAuditLog {
  id: string
  action: string
  subject_type: string | null
  subject_id: string | null
  actor: { id: string | null; display_name: string; account: string | null }
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  reason: string | null
  request_id: string | null
  created_at: string
}

export interface UserCenterOverview {
  summary: { new_users_today: number; total_users: number; active_users: number; phone_verified_users: number; profile_completed_users: number; task_claims_today: number; paid_orders_today: number; withdrawn_today_cents: number }
  pending: { low_task_reward_quota: number; task_reviews: number; task_publish_approvals: number; withdrawals: number; complaints: number; pending_fulfillment: number; after_sales: number; incomplete_profiles: number }
  platform: { total_task_claims: number; total_paid_orders: number; total_referrals: number; rewarded_referrals: number; total_withdrawn_cents: number }
  policies: { self_registration_enabled: boolean; growth_levels_enabled: boolean; referral_milestones: string[]; referral_monthly_cap: number; referral_reward_points: number; real_name_collection_enabled: boolean }
  level_rules: UserLevelRule[]
  trend: Array<{ date: string; new_users: number; task_claims: number; paid_orders: number }>
  generated_at: string
}
export interface UserLevelRule { code: string; name: string; approved_tasks: number; cash_orders: number; profile_required: boolean; benefits: string[] }
export interface ManagedEndUser {
  id: string; nickname: string; phone_masked: string | null; status: string; level: { code: string; name: string }
  assets: { points_available: number; points_frozen: number; wallet_available_cents: number; wallet_frozen_cents: number; coupons_available: number }
  task_metrics: { claimed: number; approved: number }; cash_order_count: number; referral_count: number
  verification: { phone: boolean; profile: boolean; wechat: boolean }; created_at: string; last_login_at: string | null
}
export interface ManagedReferral {
  id: string; inviter: { id: string; nickname: string; phone_masked: string | null }; referred_user: { id: string; nickname: string; phone_masked: string | null }
  status: string; first_task_completed: boolean; first_cash_order_completed: boolean; reward_points: number; bound_at: string; rewarded_at: string | null
}
export interface ManagedAccountVerification {
  user: { id: string; nickname: string; phone_masked: string | null }; phone_verified: boolean; profile_completed: boolean; wechat_bound: boolean
  status: 'verified' | 'partial' | 'unverified'; real_name_status: 'not_collected'; updated_at: string
}
export interface ManagedNotificationTemplateVersion {
  version_no: number; subject: string; body: string; allowed_variables: string[]; provider_template_id: string | null;
  status: string; published_at: string | null; created_at: string
}
export interface ManagedNotificationTemplate {
  id: string; scene_code: string; channel: string; status: string; current_version_no: number;
  current_version: ManagedNotificationTemplateVersion | null; versions?: ManagedNotificationTemplateVersion[]; updated_at: string | null
}
export interface ManagedNotificationRule {
  scene_code: string; domain: 'task' | 'order' | 'community'; domain_name: string; name: string; category: string;
  enabled: boolean; channels: string[]; allowed_channels: string[]; allowed_variables: string[]; essential: boolean;
  deep_link: string; subscription_context: string; sort: number; lock_version: number; updated_at: string | null;
  default_template: { subject: string; body: string };
  templates: Record<string, { configured: boolean; ready: boolean; template_id: string | null; version_no: number | null; provider_template_id: string | null }>
}
export interface ManagedNotificationCampaign {
  id: string; name: string; title: string; body: string; channels: string[]; status: string;
  recipient_count: number; sent_count: number; scheduled_at: string | null; completed_at: string | null; created_at: string
}
export interface ManagedNotificationDelivery {
  id: number; notification_id: string; title: string; scene_code: string; channel: string; status: 'retrying' | 'failed';
  retry_count: number; next_retry_at: string | null; error_code: string | null; error_message: string | null; updated_at: string
}
export interface RuntimeResilience { readiness: { status: 'ready' | 'not_ready'; checks: Record<string, { ready: boolean; latency_ms: number }>; checked_at: string }; consistency: { healthy: boolean; checks: Record<string, { violations: number; skipped: boolean }>; checked_at: string } }
export interface UatRun { id: string; release_version: string; environment: string; status: 'passed' | 'passed_local' | 'failed'; summary: { passed: number; failed: number; blocked: number; total: number }; report_hash: string; completed_at: string; cases: Array<{ case_code: string; scope: string; status: string; evidence: Record<string, unknown> }> }
export interface ManagedFeatureFlag { code: string; name: string; mode: 'disabled' | 'percentage' | 'enabled'; rollout_percentage: number; audience: Record<string, unknown> | null; version: number; updated_at: string }
export interface ManagedSupportMessage { id: string; sender_type: 'user' | 'admin'; content: string; created_at: string }
export interface ManagedSupportTicket {
  id: string; user_id: number; category: string; subject: string
  status: 'open' | 'processing' | 'waiting_user' | 'waiting_service' | 'resolved' | 'closed'
  priority: 'low' | 'normal' | 'high' | 'urgent'; related_type: string | null; related_public_id: string | null
  assigned_admin_id: number | null; created_at: string; updated_at: string; messages: ManagedSupportMessage[]
}
export interface ManagedRelease { id: string; release_version: string; environment: string; commit_sha: string; artifact_hash: string; configuration_hash: string; status: string; rollout_percentage: number; deployed_at: string | null; rolled_back_at: string | null }

interface ApiEnvelope<T> {
  code: string
  message: string
  data: T
  request_id: string
}

interface ApiFailure {
  code?: string
  message?: string
  request_id?: string
  details?: {
    errors?: Record<string, string[]>
  }
}

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly requestId?: string
  readonly validationErrors: Record<string, string[]>

  constructor(
    message: string,
    status: number,
    code: string,
    requestId?: string,
    validationErrors: Record<string, string[]> = {},
  ) {
    super(message)
    this.status = status
    this.code = code
    this.requestId = requestId
    this.validationErrors = validationErrors
  }
}

const validationFieldLabels: Record<string, string> = {
  provider: '微信应用配置',
  driver: '运行模式',
  app_id: 'AppID',
  app_secret: 'AppSecret',
  timeout_seconds: '请求超时时间',
  reason: '修改原因',
  current_password: '当前管理员密码',
  mfa_code: 'MFA 验证码',
  cover_image_url: '任务封面图',
  supported_devices: '支持设备',
  category: '任务分类',
  task_type: '参与频率',
  title: '任务标题',
  description: '任务说明',
  tags: '任务标签',
  rewards: '奖励设置',
  reward_budgets: '奖励预算池',
  reward_quota_total: '奖励总名额',
  reward_quota_per_cycle: '每周期奖励上限',
  completion_window_hours: '领取后完成时限',
  submission_limit: '最大提交次数',
  review_deadline_hours: '审核期限',
  virtual_view_count: '虚拟浏览数',
  claim_starts_at: '领取开始时间',
  claim_ends_at: '领取截止时间',
  submit_ends_at: '提交截止时间',
  steps: '任务步骤',
}

function friendlyValidationMessage(errors: Record<string, string[]>): string | undefined {
  const entry = Object.entries(errors)[0]
  if (!entry) return undefined

  const [field, messages] = entry
  const rootField = field.replace(/\.\d+(?=\.|$)/g, '').split('.')[0]
  const label = validationFieldLabels[rootField] ?? field
  const rawMessage = messages[0] || '参数不正确'

  if (rootField === 'tags' && /required|不能为空|不得为空|不能留空/i.test(rawMessage)) {
    return '任务标签未填写。该项为选填，不需要标签时可直接留空提交。'
  }
  if (/required/i.test(rawMessage)) return `${label}不能为空。`
  if (/不能为空|不得为空|不能留空/.test(rawMessage)) return `${label}不能为空。`
  if (/valid url|url format/i.test(rawMessage)) return `${label}的网址格式不正确。`
  if (/integer/i.test(rawMessage)) return `${label}必须是整数。`
  if (/at least/i.test(rawMessage)) return `${label}未达到最小要求。`
  if (/greater than/i.test(rawMessage)) return `${label}超出允许范围。`

  return `${label}：${rawMessage}`
}

const ACCESS_KEY = 'task_mall_admin_access'
const REFRESH_KEY = 'task_mall_admin_refresh'

function saveTokens(tokens: Pick<AdminTokens, 'access_token' | 'refresh_token'>) {
  sessionStorage.setItem(ACCESS_KEY, tokens.access_token)
  sessionStorage.setItem(REFRESH_KEY, tokens.refresh_token)
}

export function clearTokens() {
  sessionStorage.removeItem(ACCESS_KEY)
  sessionStorage.removeItem(REFRESH_KEY)
}

export function hasStoredSession() {
  return Boolean(sessionStorage.getItem(ACCESS_KEY) && sessionStorage.getItem(REFRESH_KEY))
}

async function parseResponse<T>(response: Response): Promise<T> {
  if (response.status === 204) return undefined as T

  const payload = (await response.json().catch(() => ({}))) as ApiEnvelope<T> & ApiFailure
  if (!response.ok) {
    const validationErrors = payload.details?.errors ?? {}
    throw new ApiError(
      friendlyValidationMessage(validationErrors) || payload.message || '请求失败，请稍后再试。',
      response.status,
      payload.code || 'HTTP_ERROR',
      payload.request_id,
      validationErrors,
    )
  }

  return payload.data
}

async function refreshSession(): Promise<boolean> {
  const refreshToken = sessionStorage.getItem(REFRESH_KEY)
  if (!refreshToken) return false

  const response = await fetch('/api/v1/admin/auth/refresh', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ refresh_token: refreshToken }),
  })

  if (!response.ok) {
    clearTokens()
    return false
  }

  const tokens = await parseResponse<AdminTokens>(response)
  saveTokens(tokens)
  return true
}

async function request<T>(path: string, init: RequestInit = {}, canRetry = true): Promise<T> {
  const accessToken = sessionStorage.getItem(ACCESS_KEY)
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body && !(init.body instanceof FormData)) headers.set('Content-Type', 'application/json')
  if (accessToken) headers.set('Authorization', `Bearer ${accessToken}`)

  const response = await fetch(`/api/v1${path}`, { ...init, headers })
  if (response.status === 401 && canRetry && (await refreshSession())) {
    return request<T>(path, init, false)
  }

  return parseResponse<T>(response)
}

async function requestBlob(path: string, canRetry = true): Promise<Blob> {
  const accessToken = sessionStorage.getItem(ACCESS_KEY)
  const headers = new Headers({ Accept: '*/*' })
  if (accessToken) headers.set('Authorization', `Bearer ${accessToken}`)
  const response = await fetch(`/api/v1${path}`, { headers })
  if (response.status === 401 && canRetry && (await refreshSession())) {
    return requestBlob(path, false)
  }
  if (!response.ok) await parseResponse<never>(response)
  return response.blob()
}

export async function login(account: string, password: string): Promise<AdminTokens | AdminMfaChallenge> {
  const result = await request<AdminTokens | AdminMfaChallenge>('/admin/auth/login', {
    method: 'POST',
    body: JSON.stringify({ account, password }),
  }, false)
  if (!('mfa_required' in result)) saveTokens(result)
  return result
}

export async function verifyAdminMfa(challenge: AdminMfaChallenge, code: string): Promise<AdminTokens> {
  const tokens = await request<AdminTokens>('/admin/auth/mfa/verify', {
    method: 'POST',
    body: JSON.stringify({ challenge_id: challenge.challenge_id, challenge_token: challenge.challenge_token, code }),
  }, false)
  saveTokens(tokens)
  return tokens
}

export async function loadSession(): Promise<{ admin: AdminProfile; navigation: NavigationItem[] }> {
  const [admin, navigation] = await Promise.all([
    request<AdminProfile>('/admin/me'),
    request<NavigationItem[]>('/admin/navigation'),
  ])

  return { admin, navigation }
}

export async function logout(): Promise<void> {
  try {
    await request<void>('/admin/auth/logout', { method: 'POST', body: JSON.stringify({}) }, false)
  } finally {
    clearTokens()
  }
}

export async function listSettings(group?: string): Promise<{ items: SystemSetting[]; groups: string[] }> {
  const query = group ? `?group=${encodeURIComponent(group)}` : ''
  return request(`/admin/settings${query}`)
}

export async function saveSettingDraft(
  settingKey: string,
  value: SettingValue,
  reason: string,
  expectedLockVersion?: number,
): Promise<SettingDraft> {
  return request(`/admin/settings/${encodeURIComponent(settingKey)}/draft`, {
    method: 'PUT',
    body: JSON.stringify({ value, reason, expected_lock_version: expectedLockVersion }),
  })
}

export async function reviewSetting(settingKey: string, decision: 'approve' | 'reject', comment: string): Promise<SettingDraft> {
  return request(`/admin/settings/${encodeURIComponent(settingKey)}/review`, {
    method: 'POST',
    body: JSON.stringify({ decision, comment }),
  })
}

export async function publishSetting(settingKey: string): Promise<SettingVersion> {
  return request(`/admin/settings/${encodeURIComponent(settingKey)}/publish`, {
    method: 'POST',
    body: JSON.stringify({ confirm_publish: true }),
  })
}

export async function listSettingVersions(settingKey: string): Promise<{ items: SettingVersion[] }> {
  return request(`/admin/settings/${encodeURIComponent(settingKey)}/versions`)
}

export async function createSettingRollback(settingKey: string, targetVersion: number, reason: string): Promise<SettingDraft> {
  return request(`/admin/settings/${encodeURIComponent(settingKey)}/rollback`, {
    method: 'POST',
    body: JSON.stringify({ target_version: targetVersion, reason }),
  })
}

export async function getSmsServiceSettings(): Promise<SmsServiceSettings> {
  return request('/admin/sms/settings')
}

export async function saveSmsServiceSettings(input: SmsServiceSettingsInput): Promise<SmsServiceSettings> {
  return request('/admin/sms/settings', {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}

export async function sendSmsServiceTest(phone: string): Promise<SmsServiceTestResult> {
  return request('/admin/sms/settings/test', {
    method: 'POST',
    body: JSON.stringify({ phone }),
  })
}

export function getWechatSettings(): Promise<WechatSettingsBundle> {
  return request('/admin/wechat/settings')
}

export function saveWechatAppSettings(provider: 'mini' | 'official_account', input: Record<string, unknown>): Promise<WechatAppSettings> {
  return request(`/admin/wechat/settings/${provider}`, { method: 'PUT', body: JSON.stringify(input) })
}

export function testWechatAppSettings(provider: 'mini' | 'official_account'): Promise<{ accepted: boolean; tested_at: string; message: string }> {
  return request(`/admin/wechat/settings/${provider}/test`, { method: 'POST', body: JSON.stringify({}) })
}

export function uploadWechatVerificationFile(file: File): Promise<WechatVerificationFile> {
  const body = new FormData()
  body.append('file', file)
  return request('/admin/wechat/verification-files', { method: 'POST', body })
}

export function deleteWechatVerificationFile(filename: string): Promise<void> {
  return request(`/admin/wechat/verification-files/${encodeURIComponent(filename)}`, { method: 'DELETE' })
}

export function getWechatPaymentSettings(): Promise<WechatPaymentSettings> {
  return request('/admin/wechat-payment/settings')
}

export function saveWechatPaymentSettings(input: Record<string, unknown>): Promise<WechatPaymentSettings> {
  return request('/admin/wechat-payment/settings', { method: 'PUT', body: JSON.stringify(input) })
}

export function saveWechatPaymentCredentials(input: { reason: string; apiV3Key?: string; privateKey?: File; platformCertificate?: File; clear?: string[] }): Promise<WechatPaymentSettings> {
  const body = new FormData()
  body.append('reason', input.reason)
  if (input.apiV3Key) body.append('api_v3_key', input.apiV3Key)
  if (input.privateKey) body.append('merchant_private_key', input.privateKey)
  if (input.platformCertificate) body.append('platform_certificate', input.platformCertificate)
  for (const field of input.clear ?? []) body.append(`clear_${field}`, '1')
  return request('/admin/wechat-payment/credentials', { method: 'POST', body })
}

export function testWechatPaymentSettings(): Promise<{ accepted: boolean; tested_at: string; message: string }> {
  return request('/admin/wechat-payment/settings/test', { method: 'POST', body: JSON.stringify({}) })
}

export function getIdentityVerificationSettings(): Promise<IdentityVerificationSettings> {
  return request('/admin/identity-verification/settings')
}

export function saveIdentityVerificationSettings(input: Record<string, unknown>): Promise<IdentityVerificationSettings> {
  return request('/admin/identity-verification/settings', { method: 'PUT', body: JSON.stringify(input) })
}

export async function listManagedAdmins(): Promise<{ items: ManagedAdmin[] }> {
  return request('/admin/admin-users')
}

export async function listAdminRoles(): Promise<{ items: AdminRole[] }> {
  return request('/admin/roles')
}

export async function listAdminPermissions(): Promise<{ items: AdminPermission[] }> {
  return request('/admin/permissions')
}

export async function createAdminRole(input: { code: string; name: string; description?: string; permission_codes: string[]; reason: string }): Promise<AdminRole> {
  return request('/admin/roles', { method: 'POST', body: JSON.stringify(input) })
}

export async function updateAdminRole(code: string, input: { name: string; description?: string; permission_codes: string[]; reason: string }): Promise<AdminRole> {
  return request(`/admin/roles/${encodeURIComponent(code)}`, { method: 'PUT', body: JSON.stringify(input) })
}

export async function createManagedAdmin(input: { account: string; display_name: string; email?: string; password: string; role_codes: string[] }): Promise<AdminProfile> {
  return request('/admin/admin-users', { method: 'POST', body: JSON.stringify(input) })
}

export async function updateManagedAdminRoles(id: string, roleCodes: string[], reason: string): Promise<AdminProfile> {
  return request(`/admin/admin-users/${id}/roles`, { method: 'PUT', body: JSON.stringify({ role_codes: roleCodes, reason }) })
}

export async function updateManagedAdminStatus(id: string, status: 'active' | 'disabled', reason: string): Promise<AdminProfile> {
  return request(`/admin/admin-users/${id}/status`, { method: 'PUT', body: JSON.stringify({ status, reason }) })
}

export async function revokeManagedAdminSessions(id: string, reason: string): Promise<{ revoked_count: number }> {
  return request(`/admin/admin-users/${id}/revoke-sessions`, { method: 'POST', body: JSON.stringify({ reason }) })
}

export async function resetManagedAdminMfa(id: string, reason: string): Promise<{ requires_mfa: boolean }> {
  return request(`/admin/admin-users/${id}/reset-mfa`, { method: 'POST', body: JSON.stringify({ reason }) })
}

export async function enrollCurrentAdminMfa(): Promise<{ secret: string; otpauth_uri: string }> {
  return request('/admin/mfa/totp/enroll', { method: 'POST', body: JSON.stringify({}) })
}

export async function confirmCurrentAdminMfa(code: string): Promise<{ recovery_codes: string[] }> {
  return request('/admin/mfa/totp/confirm', { method: 'POST', body: JSON.stringify({ code }) })
}

export async function verifyAdminRecent(password: string, mfaCode?: string): Promise<{ verified_at: string }> {
  return request('/admin/auth/verify', { method: 'POST', body: JSON.stringify({ password, mfa_code: mfaCode }) })
}

export async function changeAdminPassword(input: {
  current_password: string
  new_password: string
  new_password_confirmation: string
  mfa_code?: string
}): Promise<void> {
  return request('/admin/auth/password', { method: 'PUT', body: JSON.stringify(input) })
}

export async function listPrivacyRequests(filters: { status?: string; request_type?: string } = {}): Promise<{ items: PrivacyRequestItem[] }> {
  const query = new URLSearchParams()
  if (filters.status) query.set('status', filters.status)
  if (filters.request_type) query.set('request_type', filters.request_type)
  const suffix = query.size ? `?${query.toString()}` : ''
  return request(`/admin/privacy-requests${suffix}`)
}
export async function listManagedLegalDocuments(): Promise<{ items: ManagedLegalDocument[] }> { return request('/admin/legal-documents') }
export async function createManagedLegalDocument(input: Record<string, unknown>): Promise<ManagedLegalDocument> {
  return request('/admin/legal-documents', { method: 'POST', body: JSON.stringify(input) })
}
export async function updateManagedLegalDocument(id: string, input: Record<string, unknown>): Promise<ManagedLegalDocument> {
  return request(`/admin/legal-documents/${id}`, { method: 'PUT', body: JSON.stringify(input) })
}
export async function publishManagedLegalDocument(id: string, reason: string): Promise<ManagedLegalDocument> {
  return request(`/admin/legal-documents/${id}/publish`, { method: 'POST', body: JSON.stringify({ confirm_legal_review: true, reason }) })
}

export async function resolvePrivacyRequest(
  id: string,
  decision: 'completed' | 'partially_completed' | 'rejected',
  resolutionNote: string,
): Promise<PrivacyRequestItem> {
  return request(`/admin/privacy-requests/${id}/resolve`, {
    method: 'POST',
    body: JSON.stringify({ decision, resolution_note: resolutionNote, confirm_identity_verified: true }),
  })
}

export async function getProductionReadiness(): Promise<ProductionReadiness> {
  return request('/admin/production-readiness')
}

export async function listRewardBudgetPools(): Promise<{ items: RewardBudgetPool[] }> {
  return request('/admin/reward-budget-pools')
}

export async function listRewardCouponTemplates(): Promise<{ items: ManagedCouponTemplate[] }> {
  return request('/admin/reward-coupon-templates')
}

export async function createRewardBudgetPool(input: { name: string; asset_type: 'points' | 'wallet_cash' | 'coupon'; coupon_template_id?: string; total_amount: number; reason: string }): Promise<RewardBudgetPool> {
  return request('/admin/reward-budget-pools', {
    method: 'POST', headers: { 'Idempotency-Key': `admin-pool-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input),
  })
}

export async function listCouponTemplates(): Promise<{ items: ManagedCouponTemplate[] }> {
  return request('/admin/coupon-templates')
}

export async function createCouponTemplate(input: Omit<ManagedCouponTemplate, 'id' | 'issued_quantity' | 'remaining_quantity' | 'status' | 'version' | 'created_at'>): Promise<ManagedCouponTemplate> {
  return request('/admin/coupon-templates', {
    method: 'POST', headers: { 'Idempotency-Key': `admin-coupon-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input),
  })
}

export async function createCouponTemplateVersion(id: string, input: Omit<ManagedCouponTemplate, 'id' | 'issued_quantity' | 'remaining_quantity' | 'status' | 'version' | 'created_at'> & { reason: string }): Promise<ManagedCouponTemplate> {
  return request(`/admin/coupon-templates/${id}/versions`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-coupon-version-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input),
  })
}

export async function changeCouponTemplateStatus(id: string, action: 'enable' | 'disable', version: number, reason: string): Promise<ManagedCouponTemplate> {
  return request(`/admin/coupon-templates/${id}/status`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-coupon-status-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ action, version, reason }),
  })
}

export async function listManagedTasks(): Promise<{ items: ManagedTask[] }> {
  return request('/admin/tasks')
}

export async function createManagedTask(input: Record<string, unknown>): Promise<ManagedTask> {
  return request('/admin/tasks', {
    method: 'POST', headers: { 'Idempotency-Key': `admin-task-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input),
  })
}

export async function updateManagedTask(id: string, input: Record<string, unknown>, version: number): Promise<ManagedTask> {
  return request(`/admin/tasks/${id}`, {
    method: 'PUT',
    body: JSON.stringify({ ...input, version }),
  })
}

export async function listManagedTaskVersions(id: string): Promise<ManagedTaskVersionSummary[]> {
  return request(`/admin/tasks/${id}/versions`)
}

export async function rollbackManagedTaskVersion(id: string, versionNo: number, currentVersion: number, reason: string): Promise<ManagedTask> {
  return request(`/admin/tasks/${id}/versions/${versionNo}/rollback`, {
    method: 'POST',
    headers: { 'Idempotency-Key': `admin-task-rollback-${Date.now()}-${crypto.randomUUID()}` },
    body: JSON.stringify({ current_version: currentVersion, reason }),
  })
}

export async function publishManagedTask(id: string, reason: string): Promise<ManagedTask> {
  return request(`/admin/tasks/${id}/status`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-task-publish-${Date.now()}-${crypto.randomUUID()}` },
    body: JSON.stringify({ action: 'publish', reason }),
  })
}

export async function transitionManagedTask(id: string, action: 'pause' | 'resume' | 'close' | 'archive', reason: string, existingClaimPolicy?: 'continue' | 'terminate_without_compensation' | 'terminate_with_compensation'): Promise<ManagedTask> {
  return request(`/admin/tasks/${id}/status`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-task-${action}-${Date.now()}-${crypto.randomUUID()}` },
    body: JSON.stringify({ action, reason, existing_claim_policy: existingClaimPolicy ?? null }),
  })
}

export async function reviewManagedTaskPublish(id: string, decision: 'approve' | 'reject', note: string): Promise<ManagedTask> {
  return request(`/admin/tasks/${id}/publish-approval`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-task-review-${Date.now()}-${crypto.randomUUID()}` },
    body: JSON.stringify({ decision, note }),
  })
}

export async function listTaskSubmissions(status = 'submitted'): Promise<{ items: ManagedTaskSubmission[] }> {
  return request(`/admin/task-submissions?status=${encodeURIComponent(status)}`)
}

export async function startTaskSubmissionReview(id: string): Promise<ManagedTaskSubmission> {
  return request(`/admin/task-submissions/${id}/start-review`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-submission-start-review-${Date.now()}-${crypto.randomUUID()}` },
  })
}

export async function listTaskReviewReasons(): Promise<{ items: TaskReviewReason[]; reviewers: Array<{ id: string; account: string; display_name: string }> }> {
  return request('/admin/task-review-reasons')
}

export async function releaseTaskSubmissionReview(id: string, reason: string): Promise<ManagedTaskSubmission> {
  return request(`/admin/task-submissions/${id}/release-review`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-submission-release-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ reason }),
  })
}

export async function transferTaskSubmissionReview(id: string, targetAdminId: string, reason: string): Promise<ManagedTaskSubmission> {
  return request(`/admin/task-submissions/${id}/transfer-review`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-submission-transfer-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ target_admin_id: targetAdminId, reason }),
  })
}

export async function listTaskRewardDeliveries(status: 'pending' | 'failed' | 'delivered' = 'failed'): Promise<{ items: ManagedTaskRewardDelivery[] }> {
  return request(`/admin/task-reward-deliveries?status=${encodeURIComponent(status)}`)
}

export async function retryTaskRewardDelivery(id: string, reason: string): Promise<{ id: string; status: string; attempt_count: number }> {
  return request(`/admin/task-reward-deliveries/${id}/retry`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-task-reward-retry-${Date.now()}-${crypto.randomUUID()}` },
    body: JSON.stringify({ reason }),
  })
}

export async function downloadTaskSubmissionFile(submissionId: string, fileId: string): Promise<Blob> {
  return requestBlob(`/admin/task-submissions/${encodeURIComponent(submissionId)}/files/${encodeURIComponent(fileId)}`)
}

export async function reviewTaskSubmission(id: string, decision: 'approve' | 'reject', reasonCode: string | null, note: string, stepResults: Array<{ step_id: string; decision: 'approve' | 'reject'; reason_code?: string | null; note?: string | null }> = []): Promise<ManagedTaskSubmission> {
  return request(`/admin/task-submissions/${id}/review`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-submission-review-${Date.now()}-${crypto.randomUUID()}` },
    body: JSON.stringify({ decision, reason_code: reasonCode, note, step_results: stepResults }),
  })
}

export async function listTaskDataBatches(): Promise<{ items: TaskDataBatch[] }> { return request('/admin/task-data-batches') }
export async function getTaskDataBatch(id: string): Promise<TaskDataBatch> { return request(`/admin/task-data-batches/${encodeURIComponent(id)}`) }
export async function importTaskDataBatch(input: { name: string; file: File; field_mapping: Record<string, string> }): Promise<TaskDataBatch> {
  const body = new FormData()
  body.append('name', input.name)
  body.append('file', input.file)
  body.append('field_mapping', JSON.stringify(input.field_mapping))
  return request('/admin/task-data-batches', { method: 'POST', body })
}
export async function downloadTaskDataBatch(id: string): Promise<Blob> { return requestBlob(`/admin/task-data-batches/${encodeURIComponent(id)}/export`) }

export async function listTaskAppeals(): Promise<{ items: ManagedTaskAppeal[] }> { return request('/admin/task-appeals?status=submitted') }
export async function reviewTaskAppeal(id: string, decision: 'approve' | 'reject', reason: string): Promise<ManagedTaskAppeal> {
  return request(`/admin/task-appeals/${id}/review`, { method: 'POST', headers: { 'Idempotency-Key': `admin-appeal-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ decision, reason }) })
}
export async function listTaskRiskCases(): Promise<{ items: ManagedTaskRiskCase[] }> { return request('/admin/task-risk-cases?status=open') }
export async function reviewTaskRiskCase(id: string, decision: 'clear' | 'request_more_information' | 'confirm_violation', actions: string[], reason: string): Promise<ManagedTaskRiskCase> {
  return request(`/admin/task-risk-cases/${id}/review`, { method: 'POST', headers: { 'Idempotency-Key': `admin-risk-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ decision, actions, reason }) })
}

export async function getAdminUserAssets(userId: string): Promise<UserAssetSummary> {
  return request(`/admin/users/${encodeURIComponent(userId)}/assets`)
}

export async function listAssetAdjustments(status = 'pending'): Promise<{ items: AssetAdjustment[] }> {
  return request(`/admin/asset-adjustments?status=${encodeURIComponent(status)}`)
}

export async function createAssetAdjustment(userId: string, input: Record<string, unknown>): Promise<AssetAdjustment> {
  return request(`/admin/users/${encodeURIComponent(userId)}/asset-adjustments`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-asset-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input),
  })
}

export async function reviewAssetAdjustment(id: string, decision: 'approve' | 'reject', note: string): Promise<AssetAdjustment> {
  return request(`/admin/asset-adjustments/${id}/review`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-asset-review-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ decision, note }),
  })
}

export async function listWalletWithdrawals(status = 'reviewing'): Promise<{ items: WalletWithdrawal[] }> {
  return request(`/admin/wallet-withdrawals?status=${encodeURIComponent(status)}`)
}

export async function reviewWalletWithdrawal(id: string, decision: 'approve' | 'reject', note: string): Promise<WalletWithdrawal> {
  return request(`/admin/wallet-withdrawals/${id}/review`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-withdrawal-review-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ decision, note }),
  })
}

export async function listReconciliationRuns(): Promise<{ items: ReconciliationRun[] }> { return request('/admin/reconciliation-runs') }
export async function importReconciliationRun(businessDate: string, rows: Array<{ payment_no: string; provider_trade_no?: string; cash_amount: number; state: string }>): Promise<ReconciliationRun> {
  return request('/admin/reconciliation-runs', { method: 'POST', headers: { 'Idempotency-Key': `reconciliation-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ business_date: businessDate, rows }) })
}
export async function resolveReconciliationEntry(id: string, action: 'confirmed_channel_truth' | 'confirmed_local_truth' | 'ignored_with_reason', reason: string): Promise<ReconciliationEntry> {
  return request(`/admin/reconciliation-entries/${id}/resolve`, { method: 'POST', headers: { 'Idempotency-Key': `reconciliation-resolve-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ action, reason }) })
}

export async function listManagedProducts(): Promise<{ items: ManagedProduct[] }> { return request('/admin/products') }
export async function createManagedProduct(input: Record<string, unknown>): Promise<ManagedProduct> {
  return request('/admin/products', { method: 'POST', headers: { 'Idempotency-Key': `admin-product-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input) })
}
export async function updateManagedProduct(id: string, input: Record<string, unknown>): Promise<ManagedProduct> {
  return request(`/admin/products/${id}`, { method: 'PUT', body: JSON.stringify(input) })
}
export async function uploadMediaFile(file: File): Promise<MediaAsset> {
  const body = new FormData()
  body.append('file', file)
  return request('/admin/media/assets/upload', { method: 'POST', body })
}
export async function importMediaUrl(url: string): Promise<MediaAsset> {
  return request('/admin/media/assets/import-url', { method: 'POST', body: JSON.stringify({ url }) })
}
export async function listMediaStorageProviders(): Promise<{ items: MediaStorageProvider[] }> { return request('/admin/media/storage/providers') }
export async function configureMediaStorageProvider(provider: string, input: Record<string, unknown>): Promise<MediaStorageProvider[]> {
  return request(`/admin/media/storage/providers/${provider}`, { method: 'PUT', body: JSON.stringify(input) })
}
export async function testMediaStorageProvider(provider: string): Promise<{ ok: boolean; message: string }> {
  return request(`/admin/media/storage/providers/${provider}/test`, { method: 'POST', body: JSON.stringify({}) })
}
export async function activateMediaStorageProvider(provider: string): Promise<{ items: MediaStorageProvider[] }> {
  return request(`/admin/media/storage/providers/${provider}/activate`, { method: 'POST', body: JSON.stringify({}) })
}
export async function listMediaStorageMigrations(): Promise<{ items: MediaStorageMigration[] }> { return request('/admin/media/storage/migrations') }
export async function startMediaStorageMigration(targetProvider: string, deleteSource: boolean): Promise<MediaStorageMigration> {
  return request('/admin/media/storage/migrations', { method: 'POST', body: JSON.stringify({ target_provider: targetProvider, delete_source: deleteSource }) })
}
export async function changeManagedProductStatus(id: string, action: 'publish' | 'off_shelf', reason: string): Promise<ManagedProduct> {
  return request(`/admin/products/${id}/status`, { method: 'POST', headers: { 'Idempotency-Key': `admin-product-status-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ action, reason }) })
}
export async function listShippingTemplates(): Promise<{ items: ManagedShippingTemplate[] }> { return request('/admin/shipping-templates') }
export async function createShippingTemplate(input: ManagedShippingTemplateInput): Promise<ManagedShippingTemplate> {
  return request('/admin/shipping-templates', { method: 'POST', headers: { 'Idempotency-Key': `admin-shipping-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input) })
}
export async function updateShippingTemplate(id: string, input: ManagedShippingTemplateInput & { version: number }): Promise<ManagedShippingTemplate> {
  return request(`/admin/shipping-templates/${id}`, { method: 'PUT', body: JSON.stringify(input) })
}
export async function listInventories(): Promise<{ items: ManagedInventory[] }> { return request('/admin/inventories') }
export async function adjustInventory(skuId: string, input: { action: 'increase' | 'decrease' | 'set'; quantity: number; version: number; reason: string }): Promise<ManagedInventory> {
  return request(`/admin/inventories/${skuId}/adjustments`, { method: 'POST', headers: { 'Idempotency-Key': `admin-inventory-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input) })
}
export async function listManagedRefunds(): Promise<{ items: ManagedRefund[] }> { return request('/admin/refunds') }
export async function listManagedExchanges(): Promise<{ items: ManagedExchange[] }> { return request('/admin/exchanges') }
export async function listManagedAfterSaleReasons(): Promise<{ items: ManagedAfterSaleReason[] }> { return request('/admin/after-sale-reasons') }
export async function reviewManagedRefund(id: string, input: { decision: 'approve' | 'reject'; note: string; responsibility_party?: string; return_shipping_payer?: string; return_method?: string }): Promise<ManagedRefund> { return request(`/admin/refunds/${id}/review`, { method: 'POST', body: JSON.stringify(input) }) }
export async function receiveManagedReturn(id: string, note: string): Promise<ManagedRefund> { return request(`/admin/refunds/${id}/receive-return`, { method: 'POST', body: JSON.stringify({ note }) }) }
export async function reviewManagedExchange(id: string, input: { decision: 'approve' | 'reject'; reason: string; responsibility_party?: string; return_shipping_payer?: string; replacement_shipping_payer?: string; return_method?: string }): Promise<ManagedExchange> { return request(`/admin/exchanges/${id}/review`, { method: 'POST', body: JSON.stringify(input) }) }
export async function receiveManagedExchangeReturn(id: string, note: string): Promise<ManagedExchange> { return request(`/admin/exchanges/${id}/receive-return`, { method: 'POST', body: JSON.stringify({ note }) }) }
export async function fulfillManagedExchange(id: string, input: { fulfillment_type: 'logistics' | 'no_logistics'; carrier_code?: string; tracking_no?: string; no_logistics_note?: string }): Promise<ManagedExchange> { return request(`/admin/exchanges/${id}/replacement-fulfillment`, { method: 'POST', headers: { 'Idempotency-Key': `exchange-fulfillment-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input) }) }
export async function listReturnShippingCompensations(): Promise<{ items: ReturnShippingCompensation[] }> { return request('/admin/return-shipping-compensations') }
export async function reviewReturnShippingCompensation(id: string, input: { decision: 'approve' | 'reject'; approved_amount?: number; note: string }): Promise<ReturnShippingCompensation> { return request(`/admin/return-shipping-compensations/${id}/review`, { method: 'POST', headers: { 'Idempotency-Key': `shipping-compensation-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input) }) }
export async function downloadReturnShippingProof(id: string): Promise<Blob> { return requestBlob(`/admin/return-shipping-compensations/${id}/proof`) }
export async function updateManagedAfterSaleReason(reason: ManagedAfterSaleReason): Promise<ManagedAfterSaleReason> { return request(`/admin/after-sale-reasons/${reason.id}`, { method: 'PUT', body: JSON.stringify(reason) }) }
export async function listManagedOrders(filters: { keyword?: string; status?: string; fulfillment_type?: string; page?: number; page_size?: number } = {}): Promise<{ items: ManagedOrder[]; pagination: { page: number; page_size: number; total: number; last_page: number }; summary?: Record<string, number> }> {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key === 'keyword' ? 'search' : key, String(value))
  })
  return request(`/admin/orders${query.size ? `?${query.toString()}` : ''}`)
}
export async function getManagedOrder(id: string): Promise<ManagedOrder> { return request(`/admin/orders/${id}`) }
export async function createManagedFulfillment(orderId: string, input: {
  method: 'logistics' | 'no_logistics' | 'virtual_delivery'
  carrier_code?: string
  carrier_name?: string
  tracking_no?: string
  no_logistics_reason?: string
  virtual_delivery?: Record<string, unknown>
  items: Array<{ order_item_id: string; quantity: number }>
}): Promise<ManagedOrderFulfillment> {
  return request(`/admin/orders/${orderId}/fulfillments`, {
    method: 'POST',
    headers: { 'Idempotency-Key': `admin-fulfillment-${Date.now()}-${crypto.randomUUID()}` },
    body: JSON.stringify(input),
  })
}
export async function listAdminAuditLogs(filters: { keyword?: string; action?: string; subject_type?: string; actor_id?: string; started_at?: string; ended_at?: string; page?: number; page_size?: number } = {}): Promise<{ items: AdminAuditLog[]; pagination: { page: number; page_size: number; total: number; last_page: number }; scope: 'all' | 'own' }> {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value))
  })
  return request(`/admin/audit-logs${query.size ? `?${query.toString()}` : ''}`)
}
export async function listPendingCommunityPosts(): Promise<{ items: ManagedCommunityPost[] }> { return request('/admin/community/posts') }
export async function listPendingCommunityComments(): Promise<{ items: ManagedCommunityComment[] }> { return request('/admin/community/comments') }
export async function reviewCommunityPost(id: string, decision: 'approve' | 'reject', note: string): Promise<ManagedCommunityPost> { return request(`/admin/community/posts/${id}/review`, { method: 'POST', body: JSON.stringify({ decision, reason_code: decision === 'reject' ? 'content_not_compliant' : undefined, note }) }) }
export async function reviewCommunityComment(id: string, decision: 'approve' | 'reject', note: string): Promise<ManagedCommunityComment> { return request(`/admin/community/comments/${id}/review`, { method: 'POST', body: JSON.stringify({ decision, reason_code: decision === 'reject' ? 'content_not_compliant' : undefined, note }) }) }
export async function listCommunityReports(): Promise<{ items: ManagedContentReport[] }> { return request('/admin/community/reports') }
export async function listCommunityAppeals(): Promise<{ items: ManagedContentAppeal[] }> { return request('/admin/community/appeals') }
export async function moderateCommunityContent(type: 'post' | 'comment', id: string, decision: 'warning' | 'hide' | 'delete', restrictions: string[] = []): Promise<{ id: string }> { return request(`/admin/community/${type}/${id}/moderate`, { method: 'POST', headers: { 'Idempotency-Key': `moderation-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ decision, reason_code: 'other', note: '依据举报材料执行社区治理处置。', restrictions, restriction_days: restrictions.length ? 7 : undefined }) }) }
export async function reviewCommunityAppeal(id: string, decision: 'approve' | 'reject'): Promise<ManagedContentAppeal> { return request(`/admin/community/appeals/${id}/review`, { method: 'POST', body: JSON.stringify({ decision, note: decision === 'approve' ? '复核确认原处置有误，恢复内容、奖励与限制。' : '复核维持原处置。' }) }) }
export async function listCommunityActivities(status?: CommunityActivityStatus): Promise<{ items: ManagedCommunityActivity[] }> {
  const query = status ? `?${new URLSearchParams({ status }).toString()}` : ''
  return request(`/admin/community/activities${query}`)
}

export async function listWalletRecharges(status = 'pending_review'): Promise<{ items: WalletRecharge[] }> {
  return request(`/admin/wallet-recharges?status=${encodeURIComponent(status)}`)
}
export async function reviewWalletRecharge(id: string, decision: 'approve' | 'reject', note: string): Promise<WalletRecharge> {
  return request(`/admin/wallet-recharges/${id}/review`, { method: 'POST', body: JSON.stringify({ decision, note }) })
}
export async function downloadWalletRechargeProof(rechargeId: string, fileId: string): Promise<Blob> {
  return requestBlob(`/admin/wallet-recharges/${encodeURIComponent(rechargeId)}/proofs/${encodeURIComponent(fileId)}`)
}
export async function listWalletRechargeCards(status = ''): Promise<{ items: WalletRechargeCard[] }> {
  return request(`/admin/wallet-recharge-cards?status=${encodeURIComponent(status)}`)
}
export async function createWalletRechargeCards(input: { amount_cents: number; quantity: number; expires_at?: string }): Promise<{ cards: WalletRechargeCard[]; warning: string }> {
  return request('/admin/wallet-recharge-cards', { method: 'POST', body: JSON.stringify(input) })
}
export async function listManagedTaskTemplates(): Promise<{ items: ManagedTaskTemplate[] }> { return request('/admin/task-templates') }
export async function getManagedTaskTemplate(id: string): Promise<ManagedTaskTemplate> { return request(`/admin/task-templates/${id}`) }
export async function createManagedTaskTemplate(input: Record<string, unknown>): Promise<ManagedTaskTemplate> {
  return request('/admin/task-templates', { method: 'POST', headers: { 'Idempotency-Key': `task-template-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input) })
}
export async function copyManagedTaskTemplate(id: string, input: { code: string; name: string }): Promise<ManagedTaskTemplate> {
  return request(`/admin/task-templates/${id}/copy`, { method: 'POST', headers: { 'Idempotency-Key': `task-template-copy-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input) })
}
export async function changeManagedTaskTemplateStatus(id: string, input: { action: 'enable' | 'disable'; version: number; reason: string }): Promise<ManagedTaskTemplate> {
  return request(`/admin/task-templates/${id}/status`, { method: 'POST', body: JSON.stringify(input) })
}
export async function listManagedTaskTemplateVersions(id: string): Promise<ManagedTaskTemplateVersion[]> { return request(`/admin/task-templates/${id}/versions`) }
export async function createTaskFromManagedTemplate(id: string, overrides: Record<string, unknown>): Promise<ManagedTask> {
  return request(`/admin/task-templates/${id}/create-task`, { method: 'POST', headers: { 'Idempotency-Key': `task-from-template-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ overrides }) })
}
export async function reviewCommunityActivity(id: string, decision: 'approve' | 'reject', note: string, reasonCode?: string): Promise<ManagedCommunityActivity> {
  return request(`/admin/community/activities/${id}/review`, { method: 'POST', body: JSON.stringify({ decision, note, reason_code: reasonCode || undefined }) })
}
export async function closeCommunityActivity(id: string, reason: string): Promise<ManagedCommunityActivity> {
  return request(`/admin/community/activities/${id}/close`, { method: 'POST', body: JSON.stringify({ reason }) })
}
export async function removeCommunityActivityParticipant(activityId: string, participantId: number, reason: string): Promise<ManagedCommunityActivity> {
  return request(`/admin/community/activities/${activityId}/participants/${participantId}/remove`, { method: 'POST', body: JSON.stringify({ reason }) })
}
export async function getCommunityHomepageConfig(): Promise<CommunityHomepageConfig> { return request('/admin/community/config') }
export async function updateCommunityHomepageConfig(input: CommunityHomepageConfigInput): Promise<CommunityHomepageConfig> {
  return request('/admin/community/config', { method: 'PUT', body: JSON.stringify(input) })
}
export async function getOperationsDashboard(): Promise<OperationsMetrics> { return request('/admin/operations/dashboard') }
export async function listManagedSupportTickets(filters: { status?: string; category?: string } = {}): Promise<{ items: ManagedSupportTicket[] }> {
  const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => Boolean(value)) as Array<[string, string]>)
  return request(`/admin/support-tickets${query.size ? `?${query.toString()}` : ''}`)
}
export async function replyManagedSupportTicket(id: string, content: string, status: 'processing' | 'waiting_user' | 'resolved' = 'waiting_user'): Promise<ManagedSupportTicket> {
  return request(`/admin/support-tickets/${id}/messages`, { method: 'POST', body: JSON.stringify({ content, status }) })
}
export async function updateManagedSupportTicket(id: string, input: { status?: ManagedSupportTicket['status']; priority?: ManagedSupportTicket['priority'] }): Promise<ManagedSupportTicket> {
  return request(`/admin/support-tickets/${id}`, { method: 'PUT', body: JSON.stringify(input) })
}
export async function getUserCenterOverview(): Promise<UserCenterOverview> { return request('/admin/user-center/overview') }
export async function listManagedEndUsers(filters: { keyword?: string; status?: string; verification?: string; referral?: string } = {}): Promise<{ items: ManagedEndUser[]; pagination: { page: number; page_size: number; total: number; last_page: number }; privacy: Record<string, boolean> }> {
  const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => Boolean(value)) as Array<[string, string]>)
  const search = query.toString()
  return request(`/admin/user-center/users${search ? `?${search}` : ''}`)
}
export async function listManagedReferrals(filters: { keyword?: string; status?: string } = {}): Promise<{ items: ManagedReferral[]; pagination: { total: number } }> {
  const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => Boolean(value)) as Array<[string, string]>)
  const search = query.toString()
  return request(`/admin/user-center/referrals${search ? `?${search}` : ''}`)
}
export async function listManagedAccountVerifications(status = ''): Promise<{ items: ManagedAccountVerification[]; pagination: { total: number }; identity_policy: { collect_id_number: boolean; collect_id_images: boolean; note: string } }> {
  return request(`/admin/user-center/verifications${status ? `?status=${encodeURIComponent(status)}` : ''}`)
}
export async function getOperationsResilience(): Promise<RuntimeResilience> { return request('/admin/operations/resilience') }
export async function listUatRuns(): Promise<{ items: UatRun[] }> { return request('/admin/operations/uat-runs') }
export async function getReleaseControl(): Promise<{ feature_flags: ManagedFeatureFlag[]; releases: ManagedRelease[] }> { return request('/admin/operations/release-control') }
export async function updateFeatureFlag(code: string, input: Record<string, unknown>): Promise<ManagedFeatureFlag> { return request(`/admin/operations/feature-flags/${code}`, { method: 'PUT', body: JSON.stringify(input) }) }
export async function listNotificationTemplates(): Promise<{ items: ManagedNotificationTemplate[] }> { return request('/admin/notification-templates') }
export async function getNotificationTemplate(id: string): Promise<ManagedNotificationTemplate> { return request(`/admin/notification-templates/${encodeURIComponent(id)}`) }
export async function saveNotificationTemplate(input: Record<string, unknown>): Promise<ManagedNotificationTemplate> { return request('/admin/notification-templates', { method: 'POST', body: JSON.stringify(input) }) }
export async function listNotificationRules(): Promise<{ items: ManagedNotificationRule[] }> { return request('/admin/notification-rules') }
export async function updateNotificationRule(sceneCode: string, input: Record<string, unknown>): Promise<ManagedNotificationRule> { return request(`/admin/notification-rules/${encodeURIComponent(sceneCode)}`, { method: 'PUT', body: JSON.stringify(input) }) }
export async function listNotificationCampaigns(): Promise<{ items: ManagedNotificationCampaign[] }> { return request('/admin/notification-campaigns') }
export async function createNotificationCampaign(input: Record<string, unknown>): Promise<ManagedNotificationCampaign> { return request('/admin/notification-campaigns', { method: 'POST', headers: { 'Idempotency-Key': `campaign-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify(input) }) }
export async function cancelNotificationCampaign(id: string, reason: string): Promise<ManagedNotificationCampaign> { return request(`/admin/notification-campaigns/${encodeURIComponent(id)}/cancel`, { method: 'POST', body: JSON.stringify({ reason }) }) }
export async function listNotificationDeliveries(): Promise<{ items: ManagedNotificationDelivery[] }> { return request('/admin/notification-deliveries') }
export async function retryNotificationDelivery(id: number, reason: string): Promise<ManagedNotificationDelivery> { return request(`/admin/notification-deliveries/${id}/retry`, { method: 'POST', body: JSON.stringify({ reason }) }) }
