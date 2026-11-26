export interface AdminProfile {
  id: string
  account: string
  display_name: string
  email: string | null
  status: string
  requires_mfa: boolean
  roles: string[]
  permissions: string[]
  campuses: Array<{ id: string; code: string; name: string; is_primary: boolean }>
}

export interface ManagedCampusAgent {
  id: string
  account: string
  display_name: string
  is_primary: boolean
  roles: string[]
}

export interface CampusAgentOption {
  id: string
  account: string
  display_name: string
  roles: string[]
}

export interface ManagedCampus {
  id: string
  code: string
  official_school_code: string | null
  name: string
  emblem_asset_id: string | null
  emblem_url: string | null
  logo_asset_id: string | null
  logo_url: string | null
  catalog_type: 'ordinary' | 'adult' | 'manual'
  education_level: string | null
  supervising_authority: string | null
  official_note: string | null
  catalog_status: 'listed' | 'unlisted' | 'manual'
  catalog_version: string | null
  province: string | null
  city: string | null
  district: string | null
  address: string | null
  latitude: number | null
  longitude: number | null
  reference_locations?: Array<{ name: string; address: string; district: string; latitude: number; longitude: number }>
  coordinate_system?: 'WGS84'
  status: 'active' | 'disabled'
  isolation_mode: 'legacy' | 'strict'
  sort_order: number
  users_count: number
  updated_at: string | null
  agents: ManagedCampusAgent[]
}

export interface CampusOperationSummary {
  total: number
  active: number
  disabled: number
  official_listed: number
  branding_configured: number
  branding_missing: number
  without_primary_agent: number
  agent_total: number
  user_total: number
  isolation_strict: number
  isolation_legacy: number
}

export interface CampusCatalogMetadata {
  source_name: string
  source_page_url: string
  published_on: string
  as_of: string
  excludes_hong_kong_macao_taiwan: boolean
  counts: { total: number; ordinary: number; adult: number; undergraduate: number; vocational: number }
  files: Array<{ catalog_type: 'ordinary' | 'adult'; url: string; sha256: string; records: number }>
  database: { listed: number; unlisted: number; last_synced_at: string | null }
}

export interface ManagedCampusList {
  items: ManagedCampus[]
  pagination: { page: number; page_size: number; total: number; last_page: number }
  summary: CampusOperationSummary
  filters: { provinces: string[] }
  catalog: CampusCatalogMetadata
}

export interface ManagedCampusAgentOverview {
  id: string
  account: string
  display_name: string
  email: string | null
  status: 'active' | 'disabled'
  requires_mfa: boolean
  last_login_at: string | null
  campuses_count: number
  covered_users_count: number
  campuses: Array<{ id: string; code: string; name: string; status: 'active' | 'disabled'; is_primary: boolean }>
}

export interface ManagedCampusAgentList {
  items: ManagedCampusAgentOverview[]
  pagination: { page: number; page_size: number; total: number; last_page: number }
  summary: { total: number; active: number; unassigned: number; campuses_covered: number; users_covered: number }
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
  refresh_token?: string
  token_type: 'Bearer'
  expires_in: number
  remembered?: boolean
  refresh_expires_at?: string
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

export type DecorationPageKey = 'home' | 'community' | 'mall' | 'account'

export interface DecorationSection {
  id: string
  type: string
  enabled: boolean
  props: Record<string, unknown>
}

export interface DecorationPageConfig {
  background_url: string
  sections: DecorationSection[]
}

export interface PageDecorationConfig {
  schema_version: number
  pages: Record<DecorationPageKey, DecorationPageConfig>
  version: number | null
}

export interface PageDecorationOverview {
  config: PageDecorationConfig
  current_version: number | null
  draft: SettingDraft | null
  versions: SettingVersion[]
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
export interface ManagedProduct { id: string; name: string; subtitle: string | null; description: string; category: { id: string; name: string } | null; gallery_images: Array<{ asset_id?: string; url: string; alt?: string | null; sort_order: number }>; status: 'draft' | 'on_sale' | 'off_shelf'; version: number; definition_version: number; skus: ManagedProductSku[] }
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
  login_enabled: boolean
  oauth_redirect_domain: string | null
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
  mini_app_id: string
  official_account_app_id: string
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

export interface WithdrawalBankCardSnapshot {
  id: string
  cardholder_name: string
  bank_name: string
  card_tail: string
  card_masked: string
  phone_masked: string
}

export interface WalletWithdrawal {
  id: string
  withdrawal_no: string
  requested_amount: number
  fee_rate: number
  fee_amount: number
  received_amount: number
  channel: string
  channel_label?: string
  bank_card?: WithdrawalBankCardSnapshot | null
  status: string
  workflow?: { status_label: string; next_action: string; is_terminal: boolean }
  review_note: string | null
  channel_reference?: string | null
  failure_message?: string | null
  created_at: string
  completed_at?: string | null
}

export interface ReconciliationEntry { id: string; payment_no: string; provider_trade_no: string | null; provider_amount: number | null; local_amount: number | null; provider_state: string | null; local_state: string | null; result: string; resolution_status: string }
export interface ReconciliationRun { id: string; provider: string; business_date: string; status: string; provider_count: number; provider_amount: number; local_count: number; local_amount: number; matched_count: number; difference_count: number; entries: ReconciliationEntry[]; completed_at: string | null }
export interface ManagedAfterSaleReason { id: string; code: string; name: string; service_types: string[]; default_responsibility: string; return_shipping_payer: string; evidence_required: boolean; enabled: boolean; sort_order: number; version: number }
export interface ReturnShippingCompensation { id: string; source_type: string; requested_amount: number; approved_amount: number; status: string; carrier_code: string; tracking_no: string; payment_proof_file_id: string; review_note: string | null; paid_at: string | null; created_at: string }
export interface AfterSaleShipment { id?: string; direction?: string; method?: string; carrier_code?: string | null; tracking_no?: string | null; no_logistics_note?: string | null; paid_shipping_amount?: number; status?: string; shipped_at?: string | null; received_at?: string | null; compensation?: ReturnShippingCompensation | null }
export interface ManagedRefund { id: string; refund_no: string; order_no?: string | null; user?: { id: string; nickname: string } | null; service_type: string; reason_name: string; status: string; refund_cash_amount: number; refund_points: number; responsibility_party: string; return_shipping_payer: string; return_method: string | null; review_note?: string | null; user_action_deadline_at: string | null; return_shipment?: AfterSaleShipment | null; compensation?: ReturnShippingCompensation | null; created_at: string }
export interface ManagedExchange { id: string; exchange_no: string; order_no?: string | null; user?: { id: string; nickname: string } | null; reason_name: string; status: string; quantity: number; responsibility_party: string; return_shipping_payer: string; replacement_shipping_payer: string; return_method: string; review_note?: string | null; return_deadline_at: string | null; replacement_auto_complete_at?: string | null; shipments?: AfterSaleShipment[]; created_at: string }
export type CommunityAuditStatus = 'pending_review' | 'published' | 'rejected' | 'hidden' | 'deleted'
export type CommunityModerationReasonCode = 'spam' | 'sexual' | 'fraud' | 'abuse' | 'infringement' | 'illegal' | 'other'
export type CommunityPostCategory = 'experience' | 'guide' | 'question' | 'showcase'
export interface ManagedCommunityPostVersion {
  version_no: number
  title: string
  content: string
  category_code: CommunityPostCategory
  review_status: 'pending' | 'approved' | 'rejected'
  review_reason_code: CommunityModerationReasonCode | null
  review_note: string | null
  reviewed_at?: string | null
}
export type CommunityPostForwardStatus = 'none' | 'success' | 'failed'
export interface CommunityPostForwardGroup {
  group_id: string
  group_name: string | null
  status: 'success' | 'failed' | 'unknown'
  error_message: string | null
  manual: boolean
  forwarded_at: string | null
}
export interface CommunityPostForwardSummary {
  status: CommunityPostForwardStatus
  groups: CommunityPostForwardGroup[]
}
export interface CommunityPostForwardResult {
  status: 'success' | 'failed'
  groups: Omit<CommunityPostForwardGroup, 'manual'>[]
}
export interface ManagedCommunityPost {
  id: string
  campus_name?: string | null
  author: { id: string; nickname: string; campus_name?: string | null }
  title: string
  content: string
  category_code: CommunityPostCategory
  category_label?: string
  location_name: string | null
  status: string
  audit_status: CommunityAuditStatus
  current_version_no: number
  published_version_no: number | null
  latest_version: ManagedCommunityPostVersion | null
  review_reason_code: CommunityModerationReasonCode | null
  review_note: string | null
  reviewed_at: string | null
  comment_count: number
  like_count: number
  favorite_count: number
  reward_granted: boolean
  published_at: string | null
  created_at: string
  forward: CommunityPostForwardSummary
}
export interface ManagedCommunityComment {
  id: string
  campus_name?: string | null
  post_id: string
  post_title: string
  parent_comment_id: string | null
  author: { id: string; nickname: string; campus_name?: string | null }
  content: string
  status: string
  audit_status: CommunityAuditStatus
  review_reason_code: CommunityModerationReasonCode | null
  review_note: string | null
  reviewed_at: string | null
  reward_granted: boolean
  published_at: string | null
  created_at: string
}
export interface CommunityAuditStats {
  total: number
  pending_review: number
  published: number
  rejected: number
  hidden: number
  deleted: number
}
export interface CommunityAdminPagination { page: number; page_size: number; total: number; last_page: number }
export interface ManagedCommunityPostFilters {
  page?: number
  page_size?: number
  id?: string
  keyword?: string
  category_code?: CommunityPostCategory
  audit_status?: CommunityAuditStatus
}
export interface ManagedCommunityCommentFilters {
  page?: number
  page_size?: number
  keyword?: string
  audit_status?: CommunityAuditStatus
}
export interface ManagedCommunityList<T> { items: T[]; pagination: CommunityAdminPagination; stats: CommunityAuditStats }
export type CommunityReviewDecision = 'approve' | 'reject'
export interface CommunityBatchReviewInput { ids: string[]; decision: CommunityReviewDecision; note: string; reasonCode?: CommunityModerationReasonCode }
export interface CommunityBatchReviewResult { id: string; success: boolean; code: string | null; message: string }
export interface CommunityPostAuthorOption { id: string; nickname: string }
export interface CommunityPostAdminCreateInput {
  authorId: string
  title: string
  content: string
  categoryCode: CommunityPostCategory
  locationName?: string
  adminNote?: string
}
export type CommunityContentSecurityProvider = 'wechat' | 'aliyun'
export type CommunityContentSecurityTestStatus = 'success' | 'failed' | 'credential_only'
export interface CommunityContentSecuritySettings {
  enabled: boolean
  provider: CommunityContentSecurityProvider
  region: string
  endpoint: string
  service: string
  last_tested_at: string | null
  last_test_status: CommunityContentSecurityTestStatus | null
  last_test_message: string | null
  aliyun: {
    configured: boolean
    source: 'backend' | 'env' | 'none'
    access_key_id_masked: string | null
    access_key_secret_masked: string | null
  }
}
export interface CommunityContentSecuritySettingsInput {
  enabled: boolean
  provider: CommunityContentSecurityProvider
  reason: string
  access_key_id?: string
  access_key_secret?: string
  clear_credentials?: boolean
  region: string
  endpoint: string
  service: string
}
export type CommunityContentSecurityVerdict = 'pass' | 'review' | 'block' | 'skipped'
export type CommunityContentSecurityRiskLevel = 'none' | 'low' | 'medium' | 'high'
export interface CommunityContentSecurityRecord {
  id: string
  content_type: 'post' | 'comment'
  content_id: string | null
  content_version_no: number | null
  content_field: 'title' | 'content'
  subject_snapshot: string | null
  content_snapshot: string
  author: { id: string; nickname: string }
  provider: CommunityContentSecurityProvider
  verdict: CommunityContentSecurityVerdict
  risk_level: CommunityContentSecurityRiskLevel | null
  risk_labels: string[]
  skip_reason: string | null
  request_id: string | null
  response_time_ms: number | null
  created_at: string
}
export interface CommunityContentSecurityRecordFilters {
  page?: number
  page_size?: number
  content_type?: 'post' | 'comment'
  provider?: CommunityContentSecurityProvider
  verdict?: CommunityContentSecurityVerdict
  risk_level?: CommunityContentSecurityRiskLevel
  keyword?: string
}
export interface CommunityContentSecurityRecordStats { total: number; pass: number; review: number; block: number; skipped: number }
export interface CommunityContentSecurityRecordList {
  items: CommunityContentSecurityRecord[]
  pagination: CommunityAdminPagination
  stats: CommunityContentSecurityRecordStats
}
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
export interface OperationsMetrics { users: { active_total: number; new_today: number }; tasks: { published: number; created_today: number; pending_reviews: number }; orders: { paid_today: number; paid_cash_today: number; pending_fulfillment: number }; after_sales: { pending_refunds: number; pending_exchanges: number }; community: { published_posts: number; pending_reviews: number; open_reports: number }; finance: { reconciliation_differences: number; pending_withdrawals: number }; notifications: { unread_total: number; failed_deliveries: number; retrying_deliveries: number }; generated_at: string }

export interface AnalyticsTrendPoint { date: string; count: number }
export interface OperationsAnalytics {
  today: {
    dau: number
    dau_change_pct: number
    new_users: number
    new_users_change_pct: number
    task_points_turnover: number
    task_points_change_pct: number
    mall_gmv_cents: number
    mall_gmv_change_pct: number
  }
  dau_trend: AnalyticsTrendPoint[]
  new_user_trend: AnalyticsTrendPoint[]
  task_claim_trend: AnalyticsTrendPoint[]
  order_trend: AnalyticsTrendPoint[]
  funnel: Array<{ label: string; count: number; ratio: number }>
  task_type_share: Array<{ label: string; count: number; ratio: number }>
  campus_top5: Array<{ name: string; dau: number; task_count: number }>
  alerts: Array<{ level: 'critical' | 'warning' | 'info'; text: string; at: string }>
  generated_at: string
}

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
  ip_fingerprint: string | null
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
export interface ManagedTaskPublishingPermission {
  granted: boolean
  status: string | null
  publisher_id: string | null
  updated_at: string | null
}
export interface ManagedWechatChannel {
  provider: string; label: string
  openid: string | null; unionid: string | null
  openid_masked: string | null; unionid_masked: string | null
  nickname: string | null; avatar_url: string | null
  bound_at: string | null; last_login_at: string | null
}
export interface ManagedEndUser {
  id: string; nickname: string; phone_masked: string | null; status: string; level: { code: string; name: string }
  assets: { points_available: number; points_frozen: number; wallet_available_cents: number; wallet_frozen_cents: number; coupons_available: number }
  task_metrics: { claimed: number; approved: number }; cash_order_count: number; referral_count: number
  verification: { phone: boolean; profile: boolean; wechat: boolean }; created_at: string; last_login_at: string | null
  wechat?: { bound: boolean; channels: ManagedWechatChannel[] }
  task_publishing_permission: ManagedTaskPublishingPermission
  campus: { id: string; name: string; isolation_mode: 'legacy' | 'strict' } | null
  campus_profile: { grade_year: number | null; major: string | null; college: string | null }
}
export interface UserCampusOption {
  id: string; name: string; province: string | null; city: string | null; isolation_mode: 'legacy' | 'strict'
}
export interface UserCampusHistoryEntry {
  id: string
  from: { id: string; name: string } | null
  to: { id: string; name: string }
  reason: string
  changed_by_admin: boolean
  created_at: string | null
}
export interface UserCampusProfile { grade_year: number | null; major: string | null; college: string | null }
export interface UserCampusHistory {
  items: UserCampusHistoryEntry[]
  current: { id: string; name: string; isolation_mode: 'legacy' | 'strict' } | null
  profile: UserCampusProfile
}
export interface ForceUserCampusInput {
  campus_id: string
  grade_year?: number | null
  major?: string | null
  college?: string | null
  reason: string
}
export interface ManagedReferral {
  id: string; inviter: { id: string; nickname: string; phone_masked: string | null }; referred_user: { id: string; nickname: string; phone_masked: string | null }
  status: string; first_task_completed: boolean; first_cash_order_completed: boolean; reward_points: number; bound_at: string; rewarded_at: string | null
}
export interface ReferralTeamPerformance {
  approved_tasks: number
  task_reward_points: number
  task_reward_wallet_cents: number
  task_reward_coupons: number
  paid_orders: number
  paid_order_gross_amount_cents: number
  paid_order_refunded_amount_cents: number
  paid_order_net_amount_cents: number
  paid_order_amount_cents: number
  referral_reward_count: number
  referral_reward_points: number
}
export interface ReferralTeamMetricDefinitions {
  effective_member: string
  approved_tasks: string
  task_rewards: string
  paid_orders: string
  paid_order_amount: string
  referral_rewards: string
}
export interface ManagedReferralTeam {
  id: string
  leader: { id: string; nickname: string; phone_masked: string | null; status: string }
  direct_member_count: number
  member_count: number
  active_member_count: number
  effective_member_count: number
  max_depth: number
  performance: ReferralTeamPerformance
  metric_scope: 'descendants_only'
  updated_at: string | null
  traversal: { truncated: boolean; max_depth_limit: number; member_limit: number }
}
export interface ManagedReferralTeamList {
  items: ManagedReferralTeam[]
  pagination: { page: number; page_size: number; total: number; last_page: number }
  metric_scope: 'descendants_only'
  metric_definitions: ReferralTeamMetricDefinitions
}
export interface ManagedReferralTeamMember {
  user: { id: string; nickname: string; phone_masked: string | null; status: string }
  parent: { id: string; nickname: string } | null
  level: number
  direct: boolean
  effective: boolean
  referral_status: string
  joined_at: string | null
  performance: ReferralTeamPerformance
}
export interface ManagedReferralTeamDetail {
  team: ManagedReferralTeam
  members: {
    items: ManagedReferralTeamMember[]
    pagination: { page: number; page_size: number; total: number; last_page: number }
  }
  metric_scope: 'descendants_only'
  metric_definitions: ReferralTeamMetricDefinitions
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
export interface ManagedFeatureFlag { code: string; name: string; mode: 'disabled' | 'percentage' | 'enabled'; rollout_percentage: number; audience: Record<string, unknown> | null; platform_modes: Partial<Record<'wechat-mini' | 'h5', 'inherit' | 'enabled' | 'disabled'>> | null; placeholder_title_reviewing: string | null; placeholder_desc_reviewing: string | null; placeholder_title_reviewed: string | null; placeholder_desc_reviewed: string | null; version: number; updated_at: string }
export interface ManagedSupportMessage { id: string; sender_type: 'user' | 'admin'; content: string; created_at: string }
export interface ManagedSupportTicket {
  id: string; user_id: number; category: string; subject: string
  status: 'open' | 'processing' | 'waiting_user' | 'waiting_service' | 'resolved' | 'closed'
  priority: 'low' | 'normal' | 'high' | 'urgent'; related_type: string | null; related_public_id: string | null
  assigned_admin_id: number | null; created_at: string; updated_at: string; messages: ManagedSupportMessage[]
  wechat_notification?: { status: 'sent' | 'skipped' | 'failed'; message: string } | null
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
  expected_version: '模块配置版本',
  status: '模块状态',
  code: '功能模块',
  package: '功能套餐',
  provider: '微信应用配置',
  driver: '运行模式',
  app_id: 'AppID',
  app_secret: 'AppSecret',
  timeout_seconds: '请求超时时间',
  isolation_mode: '隔离模式',
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
  device_id: '设备',
  wxid: '微信账号',
  group_id: '群聊',
  message: '消息内容',
  api_key: 'XP API Key',
  clear_api_key: '清除后台密钥',
  force_realtime: '强制实时同步',
  idempotency_key: '幂等键',
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

  // 后端已返回通顺的中文提示（含字段中文名）时直接呈现，避免“字段：字段不能为空”的重复。
  if (/[一-龥]/.test(rawMessage)) return rawMessage

  return `${label}：${rawMessage}`
}

const ACCESS_KEY = 'task_mall_admin_access'
const REFRESH_KEY = 'task_mall_admin_refresh'
const REMEMBER_KEY = 'task_mall_admin_remember'
const REMEMBERED_ACCOUNT_KEY = 'task_mall_admin_account'
// 仅用于清除历史版本写入浏览器本地的密码，绝不再写入该键。
const LEGACY_REMEMBERED_PASSWORD_KEY = 'task_mall_admin_password_v1'
const CAMPUS_SCOPE_KEY = 'task_mall_admin_campus_scope'

// 登录密码不得落盘：清除旧版本遗留的可逆混淆值。
try {
  localStorage.removeItem(LEGACY_REMEMBERED_PASSWORD_KEY)
} catch {
  // 隐私模式下 localStorage 不可用，忽略即可。
}

function removeTokenValues() {
  sessionStorage.removeItem(ACCESS_KEY)
  sessionStorage.removeItem(REFRESH_KEY)
  localStorage.removeItem(ACCESS_KEY)
  localStorage.removeItem(REFRESH_KEY)
}

function isRememberedSession() {
  return localStorage.getItem(REMEMBER_KEY) === '1'
}

export function getRememberedAdminAccount() {
  return localStorage.getItem(REMEMBERED_ACCOUNT_KEY) ?? ''
}

function saveRememberedAdminAccount(account: string, remember: boolean) {
  const normalizedAccount = account.trim()
  if (remember && normalizedAccount) localStorage.setItem(REMEMBERED_ACCOUNT_KEY, normalizedAccount)
  else localStorage.removeItem(REMEMBERED_ACCOUNT_KEY)
}

function legacyRememberedToken(key: string) {
  return isRememberedSession() ? localStorage.getItem(key) : null
}

function saveTokens(tokens: Pick<AdminTokens, 'access_token' | 'refresh_token' | 'remembered'>, remember = tokens.remembered ?? isRememberedSession()) {
  removeTokenValues()
  sessionStorage.setItem(ACCESS_KEY, tokens.access_token)
  if (remember) localStorage.setItem(REMEMBER_KEY, '1')
  else {
    if (tokens.refresh_token) sessionStorage.setItem(REFRESH_KEY, tokens.refresh_token)
    localStorage.removeItem(REMEMBER_KEY)
  }
}

export function clearTokens() {
  removeTokenValues()
  localStorage.removeItem(REMEMBER_KEY)
  localStorage.removeItem(CAMPUS_SCOPE_KEY)
}

export function getAdminCampusScope() {
  return localStorage.getItem(CAMPUS_SCOPE_KEY)
}

export function setAdminCampusScope(campusId: string | null) {
  if (campusId) localStorage.setItem(CAMPUS_SCOPE_KEY, campusId)
  else localStorage.removeItem(CAMPUS_SCOPE_KEY)
}

export function hasStoredSession() {
  if (isRememberedSession()) return true
  return Boolean(sessionStorage.getItem(ACCESS_KEY) && sessionStorage.getItem(REFRESH_KEY))
}

function getAdminAccessToken() {
  return sessionStorage.getItem(ACCESS_KEY) || legacyRememberedToken(ACCESS_KEY)
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

let refreshInFlight: Promise<boolean> | null = null

async function performRefresh(): Promise<boolean> {
  const remembered = isRememberedSession()
  const refreshToken = sessionStorage.getItem(REFRESH_KEY) || legacyRememberedToken(REFRESH_KEY)
  if (!remembered && !refreshToken) return false

  const response = await fetch('/api/v1/admin/auth/refresh', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(refreshToken ? { refresh_token: refreshToken } : {}),
  })

  if (!response.ok) {
    if (response.status === 401) {
      clearTokens()
      return false
    }

    return await parseResponse<never>(response)
  }

  const tokens = await parseResponse<AdminTokens>(response)
  saveTokens(tokens, tokens.remembered ?? remembered)
  return true
}

async function refreshSession(): Promise<boolean> {
  if (!refreshInFlight) {
    refreshInFlight = performRefresh().finally(() => {
      refreshInFlight = null
    })
  }

  return refreshInFlight
}

async function request<T>(path: string, init: RequestInit = {}, canRetry = true): Promise<T> {
  const accessToken = getAdminAccessToken()
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body && !(init.body instanceof FormData)) headers.set('Content-Type', 'application/json')
  if (accessToken) headers.set('Authorization', `Bearer ${accessToken}`)
  const campusScope = getAdminCampusScope()
  if (campusScope && !/^\/admin\/(auth(?:\/|$)|me$|navigation$)/.test(path)) headers.set('X-Campus-Id', campusScope)

  const response = await fetch(`/api/v1${path}`, { ...init, headers })
  if (response.status === 401 && canRetry) {
    const latestAccessToken = getAdminAccessToken()
    if (latestAccessToken && latestAccessToken !== accessToken) {
      return request<T>(path, init, false)
    }
    if (await refreshSession()) return request<T>(path, init, false)
  }

  return parseResponse<T>(response)
}

async function requestBlob(path: string, canRetry = true): Promise<Blob> {
  const accessToken = getAdminAccessToken()
  const headers = new Headers({ Accept: '*/*' })
  if (accessToken) headers.set('Authorization', `Bearer ${accessToken}`)
  const campusScope = getAdminCampusScope()
  if (campusScope) headers.set('X-Campus-Id', campusScope)
  const response = await fetch(`/api/v1${path}`, { headers })
  if (response.status === 401 && canRetry) {
    const latestAccessToken = getAdminAccessToken()
    if (latestAccessToken && latestAccessToken !== accessToken) {
      return requestBlob(path, false)
    }
    if (await refreshSession()) return requestBlob(path, false)
  }
  if (!response.ok) await parseResponse<never>(response)
  return response.blob()
}

export function adminRequest<T>(path: string, init: RequestInit = {}): Promise<T> {
  return request<T>(path, init)
}

export async function login(account: string, password: string, remember = false): Promise<AdminTokens | AdminMfaChallenge> {
  const result = await request<AdminTokens | AdminMfaChallenge>('/admin/auth/login', {
    method: 'POST',
    body: JSON.stringify({ account, password, remember }),
  }, false)
  saveRememberedAdminAccount(account, remember)
  if (!('mfa_required' in result)) {
    setAdminCampusScope(null)
    saveTokens(result, result.remembered ?? false)
  }
  return result
}

export async function verifyAdminMfa(challenge: AdminMfaChallenge, code: string, remember = false): Promise<AdminTokens> {
  const tokens = await request<AdminTokens>('/admin/auth/mfa/verify', {
    method: 'POST',
    body: JSON.stringify({ challenge_id: challenge.challenge_id, challenge_token: challenge.challenge_token, code, remember }),
  }, false)
  setAdminCampusScope(null)
  saveTokens(tokens, tokens.remembered ?? false)
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
    await request<void>('/admin/auth/logout', { method: 'POST', body: JSON.stringify({}) })
  } finally {
    clearTokens()
  }
}

export type IconConfigCategory = 'tabbar' | 'module' | 'order' | 'community' | 'service' | 'common'
export type IconConfigTone = 'default' | 'yellow' | 'cyan' | 'green' | 'blue' | 'purple' | 'red'

export interface IconConfigItem {
  id: string
  code: string
  name: string
  category: IconConfigCategory
  svg_path: string
  fallback_char: string | null
  label: string | null
  description: string | null
  tone: IconConfigTone | null
  sort_order: number
  is_active: boolean
  meta: Record<string, unknown> | null
}

export interface IconConfigInput {
  code?: string
  name?: string
  category?: IconConfigCategory
  svg_path?: string
  fallback_char?: string | null
  label?: string | null
  description?: string | null
  tone?: IconConfigTone | null
  sort_order?: number
  is_active?: boolean
}

export async function listIconConfigs(params?: { category?: IconConfigCategory; is_active?: boolean }): Promise<{ items: IconConfigItem[] }> {
  const search = new URLSearchParams()
  if (params?.category) search.set('category', params.category)
  if (typeof params?.is_active === 'boolean') search.set('is_active', String(params.is_active))
  const query = search.toString() ? `?${search.toString()}` : ''
  return request(`/admin/icon-configs${query}`)
}

export async function createIconConfig(input: IconConfigInput): Promise<{ item: IconConfigItem }> {
  return request('/admin/icon-configs', { method: 'POST', body: JSON.stringify(input) })
}

export async function updateIconConfig(id: string, input: IconConfigInput): Promise<{ item: IconConfigItem }> {
  return request(`/admin/icon-configs/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(input) })
}

export async function deleteIconConfig(id: string): Promise<void> {
  await request(`/admin/icon-configs/${encodeURIComponent(id)}`, { method: 'DELETE' })
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

export async function getPageDecoration(): Promise<PageDecorationOverview> {
  return request('/admin/page-decoration')
}

export async function savePageDecorationDraft(
  value: PageDecorationConfig,
  reason: string,
  expectedLockVersion?: number,
): Promise<SettingDraft> {
  return request('/admin/page-decoration/draft', {
    method: 'PUT',
    body: JSON.stringify({ value, reason, expected_lock_version: expectedLockVersion }),
  })
}

export async function publishPageDecoration(): Promise<SettingVersion> {
  return request('/admin/page-decoration/publish', {
    method: 'POST',
    body: JSON.stringify({ confirm_publish: true }),
  })
}

export async function listPageDecorationVersions(): Promise<{ items: SettingVersion[] }> {
  return request('/admin/page-decoration/versions')
}

export async function rollbackPageDecoration(targetVersion: number, reason: string): Promise<SettingDraft> {
  return request('/admin/page-decoration/rollback', {
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

export interface WechatOfficialAccountMenu {
  configured: boolean
  button: Array<Record<string, unknown>>
  default_button: Array<Record<string, unknown>>
}

export function getWechatOfficialAccountMenu(): Promise<WechatOfficialAccountMenu> {
  return request('/admin/wechat/official-account/menu')
}

export function publishWechatOfficialAccountMenu(reason: string): Promise<WechatOfficialAccountMenu> {
  return request('/admin/wechat/official-account/menu', { method: 'POST', body: JSON.stringify({ reason }) })
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

export async function resetManagedAdminPassword(id: string, password: string, reason: string): Promise<{ password_reset: boolean }> {
  return request(`/admin/admin-users/${id}/password`, { method: 'PUT', body: JSON.stringify({ password, reason }) })
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

export async function recordManualWithdrawalResult(id: string, decision: 'paid' | 'failed', payload: { reference?: string; note?: string }): Promise<WalletWithdrawal> {
  return request(`/admin/wallet-withdrawals/${id}/manual-result`, {
    method: 'POST', headers: { 'Idempotency-Key': `admin-withdrawal-manual-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ decision, ...payload }),
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
function communityAdminQuery(filters: object): string {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value))
  })
  return query.size ? `?${query.toString()}` : ''
}
export async function listCommunityPosts(filters: ManagedCommunityPostFilters = {}): Promise<ManagedCommunityList<ManagedCommunityPost>> {
  return request(`/admin/community/posts${communityAdminQuery(filters)}`)
}
export async function listCommunityComments(filters: ManagedCommunityCommentFilters = {}): Promise<ManagedCommunityList<ManagedCommunityComment>> {
  return request(`/admin/community/comments${communityAdminQuery(filters)}`)
}
export async function listPendingCommunityPosts(): Promise<ManagedCommunityList<ManagedCommunityPost>> {
  return listCommunityPosts({ audit_status: 'pending_review', page_size: 50 })
}
export async function listPendingCommunityComments(): Promise<ManagedCommunityList<ManagedCommunityComment>> {
  return listCommunityComments({ audit_status: 'pending_review', page_size: 50 })
}
export async function reviewCommunityPost(id: string, decision: CommunityReviewDecision, note: string, reasonCode: CommunityModerationReasonCode = 'other'): Promise<ManagedCommunityPost> {
  return request(`/admin/community/posts/${encodeURIComponent(id)}/review`, { method: 'POST', body: JSON.stringify({ decision, reason_code: decision === 'reject' ? reasonCode : undefined, note }) })
}
export async function forwardCommunityPost(id: string): Promise<CommunityPostForwardResult> {
  return request(`/admin/community/posts/${encodeURIComponent(id)}/forward`, { method: 'POST' })
}
export async function forwardManagedTask(id: string): Promise<CommunityPostForwardResult> {
  return request(`/admin/tasks/${encodeURIComponent(id)}/forward`, { method: 'POST' })
}
export async function reviewCommunityComment(id: string, decision: CommunityReviewDecision, note: string, reasonCode: CommunityModerationReasonCode = 'other'): Promise<ManagedCommunityComment> {
  return request(`/admin/community/comments/${encodeURIComponent(id)}/review`, { method: 'POST', body: JSON.stringify({ decision, reason_code: decision === 'reject' ? reasonCode : undefined, note }) })
}
export async function batchReviewCommunityPosts(input: CommunityBatchReviewInput): Promise<{ items: CommunityBatchReviewResult[] }> {
  return request('/admin/community/posts/batch-review', { method: 'POST', body: JSON.stringify({ ids: input.ids, decision: input.decision, reason_code: input.decision === 'reject' ? input.reasonCode ?? 'other' : undefined, note: input.note }) })
}
export async function batchReviewCommunityComments(input: CommunityBatchReviewInput): Promise<{ items: CommunityBatchReviewResult[] }> {
  return request('/admin/community/comments/batch-review', { method: 'POST', body: JSON.stringify({ ids: input.ids, decision: input.decision, reason_code: input.decision === 'reject' ? input.reasonCode ?? 'other' : undefined, note: input.note }) })
}
export async function searchCommunityPostAuthors(keyword = ''): Promise<{ items: CommunityPostAuthorOption[] }> {
  return request(`/admin/community/post-authors${communityAdminQuery({ keyword })}`)
}
export async function createCommunityPostAsAdmin(input: CommunityPostAdminCreateInput): Promise<ManagedCommunityPost> {
  return request('/admin/community/posts', {
    method: 'POST',
    body: JSON.stringify({
      author_id: input.authorId,
      title: input.title,
      content: input.content,
      category_code: input.categoryCode,
      location_name: input.locationName || undefined,
      admin_note: input.adminNote || undefined,
    }),
  })
}
export async function getCommunityContentSecuritySettings(): Promise<CommunityContentSecuritySettings> {
  return request('/admin/community/content-security/settings')
}
export async function saveCommunityContentSecuritySettings(input: CommunityContentSecuritySettingsInput): Promise<CommunityContentSecuritySettings> {
  return request('/admin/community/content-security/settings', { method: 'PUT', body: JSON.stringify(input) })
}
export async function testCommunityContentSecuritySettings(reason: string): Promise<CommunityContentSecuritySettings> {
  return request('/admin/community/content-security/settings/test', { method: 'POST', body: JSON.stringify({ reason }) })
}
export async function listCommunityContentSecurityRecords(filters: CommunityContentSecurityRecordFilters = {}): Promise<CommunityContentSecurityRecordList> {
  return request(`/admin/community/content-security/records${communityAdminQuery(filters)}`)
}
export async function listCommunityReports(): Promise<{ items: ManagedContentReport[] }> { return request('/admin/community/reports') }
export async function listCommunityAppeals(): Promise<{ items: ManagedContentAppeal[] }> { return request('/admin/community/appeals') }
export async function moderateCommunityContent(type: 'post' | 'comment', id: string, decision: 'warning' | 'hide' | 'delete', restrictions: string[] = [], reasonCode: CommunityModerationReasonCode = 'other', note = '依据举报材料执行社区治理处置。'): Promise<{ id: string }> { return request(`/admin/community/${type}/${encodeURIComponent(id)}/moderate`, { method: 'POST', headers: { 'Idempotency-Key': `moderation-${Date.now()}-${crypto.randomUUID()}` }, body: JSON.stringify({ decision, reason_code: reasonCode, note, restrictions, restriction_days: restrictions.length ? 7 : undefined }) }) }
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
export async function getOperationsAnalytics(): Promise<OperationsAnalytics> { return request('/admin/operations/analytics') }
export async function listManagedSupportTickets(filters: { status?: string; category?: string } = {}): Promise<{ items: ManagedSupportTicket[] }> {
  const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => Boolean(value)) as Array<[string, string]>)
  return request(`/admin/support-tickets${query.size ? `?${query.toString()}` : ''}`)
}
export async function replyManagedSupportTicket(id: string, content: string, status: 'processing' | 'waiting_user' | 'resolved' = 'waiting_user', notifyWechat = false): Promise<ManagedSupportTicket> {
  return request(`/admin/support-tickets/${id}/messages`, { method: 'POST', body: JSON.stringify({ content, status, notify_wechat: notifyWechat }) })
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
export async function updateManagedEndUserTaskPublishingPermission(userId: string, granted: boolean, reason: string): Promise<ManagedTaskPublishingPermission> {
  return request(`/admin/user-center/users/${encodeURIComponent(userId)}/task-publishing-permission`, {
    method: 'PUT',
    body: JSON.stringify({ granted, reason }),
  })
}
export async function campusOptions(): Promise<{ items: UserCampusOption[] }> {
  return request('/admin/user-center/campus-options')
}
export async function userCampusHistory(userId: string): Promise<UserCampusHistory> {
  return request(`/admin/user-center/users/${encodeURIComponent(userId)}/campus-history`)
}
export async function forceUserCampus(userId: string, input: ForceUserCampusInput): Promise<{ campus: { id: string; name: string; isolation_mode: 'legacy' | 'strict' }; profile: UserCampusProfile }> {
  return request(`/admin/user-center/users/${encodeURIComponent(userId)}/campus`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}
export async function listManagedReferrals(filters: { keyword?: string; status?: string } = {}): Promise<{ items: ManagedReferral[]; pagination: { total: number } }> {
  const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => Boolean(value)) as Array<[string, string]>)
  const search = query.toString()
  return request(`/admin/user-center/referrals${search ? `?${search}` : ''}`)
}
export async function listManagedReferralTeams(filters: { keyword?: string; status?: string; page?: number; page_size?: number } = {}): Promise<ManagedReferralTeamList> {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value))
  })
  return request(`/admin/user-center/referral-teams${query.size ? `?${query.toString()}` : ''}`)
}
export async function getManagedReferralTeam(teamId: string, filters: { keyword?: string; status?: string; level?: number; effective?: boolean; page?: number; page_size?: number } = {}): Promise<ManagedReferralTeamDetail> {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
  })
  return request(`/admin/user-center/referral-teams/${encodeURIComponent(teamId)}${query.size ? `?${query.toString()}` : ''}`)
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
export async function listManagedCampuses(filters: {
  keyword?: string; province?: string; catalog_type?: string; education_level?: string; catalog_status?: string;
  status?: string; agent_coverage?: string; branding_status?: string; isolation_mode?: 'legacy' | 'strict'; page?: number; page_size?: number
} = {}): Promise<ManagedCampusList> {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value))
  })
  return request(`/admin/campuses${query.size ? `?${query.toString()}` : ''}`)
}
export async function listManagedCampusAgents(filters: { keyword?: string; status?: string; assignment?: string; page?: number; page_size?: number } = {}): Promise<ManagedCampusAgentList> {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value))
  })
  return request(`/admin/campus-agents${query.size ? `?${query.toString()}` : ''}`)
}
export async function listCampusAgentOptions(): Promise<{ items: CampusAgentOption[] }> { return request('/admin/campus-agent-options') }
type ManagedCampusWriteInput = Pick<ManagedCampus, 'code' | 'name' | 'emblem_asset_id' | 'emblem_url' | 'logo_asset_id' | 'logo_url' | 'province' | 'city' | 'district' | 'address' | 'latitude' | 'longitude' | 'status' | 'sort_order'> & { isolation_mode?: ManagedCampus['isolation_mode'] }

export async function createManagedCampus(input: ManagedCampusWriteInput): Promise<ManagedCampus> {
  return request('/admin/campuses', { method: 'POST', body: JSON.stringify(input) })
}
export async function updateManagedCampus(id: string, input: ManagedCampusWriteInput): Promise<ManagedCampus> {
  return request(`/admin/campuses/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(input) })
}
export async function updateManagedCampusAgents(id: string, adminUserIds: string[], primaryAdminUserId?: string): Promise<ManagedCampus> {
  return request(`/admin/campuses/${encodeURIComponent(id)}/agents`, { method: 'PUT', body: JSON.stringify({ admin_user_ids: adminUserIds, primary_admin_user_id: primaryAdminUserId || null }) })
}

// ===== 校区功能模块矩阵（全局开关 × 校区三态覆盖 × 阶段套餐）=====

export type CampusModuleOverrideStatus = 'inherit' | 'enabled' | 'disabled'
export type CampusModulePackageCode = 'basic' | 'standard' | 'full'

export interface CampusModuleMatrixRow {
  code: string
  name: string
  group: string
  parent: string | null
  status: CampusModuleOverrideStatus
  version: number
  global: { mode: string | null; enabled: boolean; rollout_percentage: number | null }
  effective: boolean
  updated_by_admin: { id: string; display_name: string } | null
  updated_at: string | null
}

export interface CampusModulePackageInfo {
  code: CampusModulePackageCode
  name: string
  description: string
  modules: string[]
}

export interface CampusModuleMatrix {
  campus: { id: string; name: string; status: 'active' | 'disabled' }
  groups: Record<string, string>
  modules: CampusModuleMatrixRow[]
  packages: CampusModulePackageInfo[]
}

export async function getCampusModuleMatrix(campusId: string): Promise<CampusModuleMatrix> {
  return request(`/admin/campuses/${encodeURIComponent(campusId)}/modules`)
}

export async function updateCampusModule(
  campusId: string,
  code: string,
  input: { status: CampusModuleOverrideStatus; expected_version: number; reason?: string },
): Promise<CampusModuleMatrix> {
  return request(`/admin/campuses/${encodeURIComponent(campusId)}/modules/${encodeURIComponent(code)}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}

export async function applyCampusModulePackage(
  campusId: string,
  packageCode: CampusModulePackageCode,
  reason?: string,
): Promise<CampusModuleMatrix> {
  return request(`/admin/campuses/${encodeURIComponent(campusId)}/module-packages/${encodeURIComponent(packageCode)}`, {
    method: 'POST',
    body: JSON.stringify({ reason: reason ?? null }),
  })
}

export interface ManagedSecondHandItem {
  id: string
  title: string
  price: number
  condition: string
  condition_label: string
  description: string
  trade_type: string
  location: string
  status: string
  audit_status: string
  rejected_reason: string | null
  cover: string | null
  images: string[]
  view_count: number
  favorite_count: number
  seller: {
    id: string
    nickname: string
    avatar: string | null
    identity_verified?: boolean
    on_sale_count?: number
    sold_count?: number
    rating_average?: number | null
    rating_count?: number
  }
  category: { id: string; name: string } | null
  submitted_at?: string | null
  reviewed_at?: string | null
  reviewed_by_name?: string | null
  created_at: string
}

/** 实名认证人工审核记录（脱敏视图） */
export interface IdentityVerificationRecord {
  id: string
  status: 'pending' | 'verified' | 'failed' | 'provider_error'
  provider: string
  real_name_masked: string | null
  identity_number_masked: string | null
  failure_code: string | null
  submitted_at: string | null
  verified_at: string | null
  reviewed_at: string | null
  review_note: string | null
  reviewed_by_name: string | null
  user: { id: string; nickname: string; avatar: string | null }
}

export interface IdentityVerificationList {
  items: IdentityVerificationRecord[]
  pagination: { page: number; page_size: number; total: number; last_page?: number }
}

export async function listIdentityVerifications(filters: { status?: string; page?: number; page_size?: number } = {}): Promise<IdentityVerificationList> {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value))
  })
  return request(`/admin/identity-verifications${query.size ? `?${query.toString()}` : ''}`)
}

export async function reviewIdentityVerification(id: string, input: { decision: 'approve' | 'reject'; note?: string }): Promise<IdentityVerificationRecord> {
  return request(`/admin/identity-verifications/${encodeURIComponent(id)}/review`, { method: 'POST', body: JSON.stringify(input) })
}

export interface ManagedSecondHandRefund {
  id: string
  order_id: string
  amount: number
  reason: string
  images: string[]
  status: string
  seller_note: string | null
  seller_responded_at: string | null
  admin_note: string | null
  arbitrated_at: string | null
  auto_approve_at: string | null
  buyer: { id: string; nickname: string; avatar: string | null }
  seller: { id: string; nickname: string; avatar: string | null }
  created_at: string
}

export interface SecondHandItemList {
  items: ManagedSecondHandItem[]
  pagination: { page: number; page_size: number; total: number; last_page: number }
}

export interface SecondHandRefundList {
  items: ManagedSecondHandRefund[]
  pagination: { page: number; page_size: number; total: number; last_page: number }
}

export async function listSecondHandItems(filters: { audit_status?: string; page?: number; page_size?: number } = {}): Promise<SecondHandItemList> {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value))
  })
  return request(`/admin/second-hand/items${query.size ? `?${query.toString()}` : ''}`)
}
export async function reviewSecondHandItem(id: string, input: { decision: 'approve' | 'reject'; reason?: string }): Promise<ManagedSecondHandItem> {
  return request(`/admin/second-hand/items/${encodeURIComponent(id)}/review`, { method: 'POST', body: JSON.stringify(input) })
}
export async function listSecondHandRefunds(filters: { status?: string; page?: number; page_size?: number } = {}): Promise<SecondHandRefundList> {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value))
  })
  return request(`/admin/second-hand/refunds${query.size ? `?${query.toString()}` : ''}`)
}
export async function arbitrateSecondHandRefund(id: string, input: { decision: 'approve' | 'reject'; note: string }): Promise<ManagedSecondHandRefund> {
  return request(`/admin/second-hand/refunds/${encodeURIComponent(id)}/arbitrate`, { method: 'POST', body: JSON.stringify(input) })
}

// ===== 地推运营 =====
export interface PushTeamItem {
  id: string
  name: string
  slogan: string | null
  intro: string | null
  level_code: string
  level_name: string
  status: 'recruiting' | 'active' | 'suspended' | 'closed'
  settlement_cycle: 'weekly' | 'monthly'
  review_hours: number
  member_limit: number
  member_count: number
  month_orders: number
  month_commission: string
  team_bonus_rate: number
  leader: { id: string; nickname: string } | null
  campus: { id: string; name: string } | null
}
export interface PushTeamList {
  items: PushTeamItem[]
  pagination: { page: number; page_size: number; total: number }
  summary: { total: number; recruiting: number; members: number; month_orders: number }
}
export interface PushTeamInput {
  campus_id?: number
  name?: string
  slogan?: string | null
  intro?: string | null
  leader_user_id?: string | null
  level_code?: string
  status?: string
  settlement_cycle?: string
  review_hours?: number
  member_limit?: number
}

export interface PushMemberItem {
  id: number
  user_id: string | null
  nickname: string
  team_name: string | null
  role: 'leader' | 'member'
  level_code: string
  status: 'active' | 'left'
  month_orders: number
  month_commission: string
  joined_at: string | null
}
export interface PushMemberList {
  items: PushMemberItem[]
  pagination: { page: number; page_size: number; total: number }
  summary: { active: number; leaders: number; month_orders: number }
}

export interface PushLevelItem {
  code: string
  name: string
  coefficient: number
  team_bonus_rate: number
  min_monthly_orders: number
  sort_order: number
  status: 'active' | 'inactive'
}

export interface PushTaskItem {
  id: string
  title: string
  description: string | null
  cover_text: string | null
  reward_amount: string
  quota: number
  claimed_count: number
  remaining: number | null
  per_user_limit: number
  starts_at: string | null
  ends_at: string | null
  status: 'draft' | 'published' | 'closed'
}
export interface PushTaskList {
  items: PushTaskItem[]
  pagination: { page: number; page_size: number; total: number }
}
export interface PushTaskInput {
  campus_id?: number
  title?: string
  description?: string | null
  cover_text?: string | null
  reward_amount?: number
  quota?: number
  per_user_limit?: number
  starts_at?: string | null
  ends_at?: string | null
  status?: string
}

export interface PushApplicationItem {
  id: string
  type: 'join' | 'leader'
  status: 'pending' | 'approved' | 'rejected'
  team_name: string | null
  message: string | null
  review_note: string | null
  created_at: string | null
  reviewed_at: string | null
  user: { id: string; nickname: string } | null
  team: { id: string; name: string } | null
}
export interface PushApplicationList {
  items: PushApplicationItem[]
  pagination: { page: number; page_size: number; total: number }
  summary: { pending: number; leader_pending: number }
}

export interface PushSubmissionItem {
  id: string
  status: 'pending' | 'approved' | 'rejected'
  images: string[]
  remark: string | null
  contact_name: string | null
  contact_phone: string | null
  reject_reason: string | null
  level_code: string
  commission: string
  team_bonus: string
  settled: boolean
  created_at: string | null
  reviewed_at: string | null
  task: PushTaskItem | null
  user: { id: string; nickname: string } | null
}
export interface PushSubmissionList {
  items: PushSubmissionItem[]
  pagination: { page: number; page_size: number; total: number }
  summary: { pending: number; approved: number; rejected: number }
}

export interface PushSettlementMember {
  id: string
  batch_no: string
  period_start: string
  period_end: string
  status: 'draft' | 'pending' | 'paid'
  submission_count: number
  total_commission: string
  total_team_bonus: string
  total_amount: string
  confirmed_at: string | null
  paid_at: string | null
  created_at: string | null
  items?: Array<{
    id: number
    user_id: string | null
    nickname: string
    team_name: string | null
    level_code: string
    submission_count: number
    commission: string
    team_bonus: string
    status: 'pending' | 'paid'
  }>
}
export interface PushSettlementList {
  items: PushSettlementMember[]
  pagination: { page: number; page_size: number; total: number }
  summary: { pending_amount: string; paid_amount: string }
}

const pushQuery = (filters: Record<string, unknown>) => {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') query.set(key, String(value))
  })
  return query.size ? `?${query.toString()}` : ''
}

export function listPushTeams(filters: Record<string, unknown> = {}): Promise<PushTeamList> {
  return request(`/admin/push/teams${pushQuery(filters)}`)
}
export function createPushTeam(input: PushTeamInput): Promise<PushTeamItem> {
  return request('/admin/push/teams', { method: 'POST', body: JSON.stringify(input) })
}
export function updatePushTeam(id: string, input: PushTeamInput): Promise<PushTeamItem> {
  return request(`/admin/push/teams/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(input) })
}
export function listPushMembers(filters: Record<string, unknown> = {}): Promise<PushMemberList> {
  return request(`/admin/push/members${pushQuery(filters)}`)
}
export function updatePushMember(id: number, input: { level_code?: string; status?: string }): Promise<{ updated: boolean }> {
  return request(`/admin/push/members/${id}`, { method: 'PUT', body: JSON.stringify(input) })
}
export function listPushLevels(): Promise<{ items: PushLevelItem[] }> {
  return request('/admin/push/levels')
}
export function savePushLevel(input: Partial<PushLevelItem> & { code: string }): Promise<PushLevelItem> {
  return request('/admin/push/levels', { method: 'POST', body: JSON.stringify(input) })
}
export function updatePushLevel(code: string, input: Partial<PushLevelItem>): Promise<PushLevelItem> {
  return request(`/admin/push/levels/${encodeURIComponent(code)}`, { method: 'PUT', body: JSON.stringify(input) })
}
export function listPushTasks(filters: Record<string, unknown> = {}): Promise<PushTaskList> {
  return request(`/admin/push/tasks${pushQuery(filters)}`)
}
export function createPushTask(input: PushTaskInput): Promise<PushTaskItem> {
  return request('/admin/push/tasks', { method: 'POST', body: JSON.stringify(input) })
}
export function updatePushTask(id: string, input: PushTaskInput): Promise<PushTaskItem> {
  return request(`/admin/push/tasks/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(input) })
}
export function listPushApplications(filters: Record<string, unknown> = {}): Promise<PushApplicationList> {
  return request(`/admin/push/applications${pushQuery(filters)}`)
}
export function reviewPushApplication(id: string, input: { decision: 'approve' | 'reject'; note?: string }): Promise<PushApplicationItem> {
  return request(`/admin/push/applications/${encodeURIComponent(id)}/review`, { method: 'POST', body: JSON.stringify(input) })
}
export function listPushSubmissions(filters: Record<string, unknown> = {}): Promise<PushSubmissionList> {
  return request(`/admin/push/submissions${pushQuery(filters)}`)
}
export function reviewPushSubmission(id: string, input: { decision: 'approve' | 'reject'; reason?: string }): Promise<PushSubmissionItem> {
  return request(`/admin/push/submissions/${encodeURIComponent(id)}/review`, { method: 'POST', body: JSON.stringify(input) })
}
export function listPushSettlements(filters: Record<string, unknown> = {}): Promise<PushSettlementList> {
  return request(`/admin/push/settlements${pushQuery(filters)}`)
}
export function createPushSettlement(input: { period_start: string; period_end: string }): Promise<PushSettlementMember> {
  return request('/admin/push/settlements', { method: 'POST', body: JSON.stringify(input) })
}
export function getPushSettlement(id: string): Promise<PushSettlementMember> {
  return request(`/admin/push/settlements/${encodeURIComponent(id)}`)
}
export function confirmPushSettlement(id: string): Promise<PushSettlementMember> {
  return request(`/admin/push/settlements/${encodeURIComponent(id)}/confirm`, { method: 'POST', body: JSON.stringify({}) })
}
export function payPushSettlement(id: string): Promise<PushSettlementMember> {
  return request(`/admin/push/settlements/${encodeURIComponent(id)}/pay`, { method: 'POST', body: JSON.stringify({}) })
}

export interface XpRobotSettings {
  configured: boolean
  masked: string | null
  source: 'backend' | 'env' | 'none'
  base_url: string
  last_tested_at: string | null
  last_test_status: 'success' | 'failed' | null
  last_test_message: string | null
}

export interface XpRobotDevice {
  device_id: string
  description?: string | null
  online?: boolean
  enabled?: boolean
  last_connected_at?: string | null
}

export interface XpRobotAccount {
  wxid: string
  nickname?: string | null
  account_type?: string | null
  status?: string | null
}

export interface XpRobotGroup {
  group_id: string
  name?: string | null
  nickname?: string | null
  group_name?: string | null
  member_count?: number | null
}

export type XpRobotMsgType = 'text' | 'image' | 'link'
export interface XpRobotLinkPayload {
  link_url?: string
  title?: string
  desc?: string
  image_url?: string
}
export interface XpRobotMessage {
  id: string
  batch_id: string | null
  batch_sequence: number | null
  device_id: string
  resolved_device_id: string | null
  wxid: string
  group_id: string
  group_name: string | null
  forward_config_id?: string | null
  trigger_type?: 'MANUAL' | 'CONFIG_TEST' | 'AUTO_FORWARD'
  message: string | null
  msg_type: XpRobotMsgType
  payload: XpRobotLinkPayload | null
  cmd_id: string | null
  status: string
  provider_status: string | null
  queued: boolean
  error_code: string | null
  error_message: string | null
  submitted_at: string | null
  last_synced_at: string | null
  scheduled_at: string | null
  dispatched_at: string | null
  canceled_at: string | null
  created_at: string | null
}

export interface XpRobotMessageBatchItemInput {
  msg_type: XpRobotMsgType
  message?: string | null
  link_url?: string
  title?: string
  desc?: string
  image_url?: string
}
export interface XpRobotMessageBatchInput {
  device_id: string
  wxid: string
  group_id: string
  group_name?: string
  scheduled_at?: string | null
  items: XpRobotMessageBatchItemInput[]
}
export interface XpRobotMessageBatch {
  batch_id: string
  batch_size: number
  items: XpRobotMessage[]
}

export interface XpRobotTestResult {
  accepted: boolean
  tested_at: string
  message: string
  device_count: number
  online_count: number
}

export function getXpRobotSettings(): Promise<XpRobotSettings> {
  return adminRequest('/admin/robot/settings')
}
export function updateXpRobotSettings(input: { api_key?: string; clear_api_key?: boolean; reason: string }): Promise<XpRobotSettings> {
  return adminRequest('/admin/robot/settings', { method: 'PUT', body: JSON.stringify(input) })
}
export function testXpRobotSettings(): Promise<XpRobotTestResult> {
  return adminRequest('/admin/robot/settings/test', { method: 'POST', body: JSON.stringify({}) })
}
export function listXpRobotDevices(status?: 'enabled' | 'disabled'): Promise<{ items: XpRobotDevice[]; total: number; online_count: number }> {
  const query = status ? `?status=${status}` : ''
  return adminRequest(`/admin/robot/devices${query}`)
}
export function listXpRobotAccounts(deviceId: string): Promise<{ items: XpRobotAccount[] }> {
  return adminRequest(`/admin/robot/accounts?device_id=${encodeURIComponent(deviceId)}`)
}
export function listXpRobotGroups(deviceId: string, wxid: string, forceRealtime = false): Promise<{ items: XpRobotGroup[] }> {
  const query = new URLSearchParams({ device_id: deviceId, wxid, force_realtime: String(forceRealtime) })
  return adminRequest(`/admin/robot/groups?${query.toString()}`)
}
export function listXpRobotMessages(): Promise<{ items: XpRobotMessage[] }> {
  return adminRequest('/admin/robot/messages')
}
export function sendXpRobotGroupText(
  input: { device_id: string; wxid: string; group_id: string; message: string; group_name?: string },
  idempotencyKey: string,
): Promise<XpRobotMessage> {
  return adminRequest('/admin/robot/messages/group-text', {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: JSON.stringify(input),
  })
}
export function sendXpRobotMessage(
  input: {
    device_id: string
    wxid: string
    group_id: string
    group_name?: string
    msg_type: XpRobotMsgType
    message?: string | null
    link_url?: string
    title?: string
    desc?: string
    image_url?: string
    scheduled_at?: string | null
  },
  idempotencyKey: string,
): Promise<XpRobotMessage> {
  return adminRequest('/admin/robot/messages', {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: JSON.stringify(input),
  })
}
export function cancelXpRobotMessage(id: string): Promise<XpRobotMessage> {
  return adminRequest(`/admin/robot/messages/${encodeURIComponent(id)}/cancel`, { method: 'POST', body: JSON.stringify({}) })
}
export function sendXpRobotMessageBatch(input: XpRobotMessageBatchInput, idempotencyKey: string): Promise<XpRobotMessageBatch> {
  return adminRequest('/admin/robot/message-batches', {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: JSON.stringify(input),
  })
}
export function cancelXpRobotMessageBatch(batchId: string): Promise<XpRobotMessageBatch> {
  return adminRequest(`/admin/robot/message-batches/${encodeURIComponent(batchId)}/cancel`, { method: 'POST', body: JSON.stringify({}) })
}
export function refreshXpRobotMessage(id: string): Promise<XpRobotMessage> {
  return adminRequest(`/admin/robot/messages/${encodeURIComponent(id)}/refresh`, { method: 'POST', body: JSON.stringify({}) })
}

export interface XpRobotForwardGroup {
  group_id: string
  group_name: string | null
}

export interface XpRobotForwardTarget {
  target_id: string
  device_id: string
  device_name: string | null
  wxid: string
  account_name: string | null
  groups: XpRobotForwardGroup[]
}

export interface XpRobotForumCategory {
  code: string
  name: string
}

export interface XpRobotSchoolOption {
  id: string
  name: string
  province: string | null
  city: string | null
}

export type XpRobotForwardPostStatus = 'published' | 'pending_review' | 'rejected' | 'hidden' | 'deleted'

export const XP_ROBOT_FORWARD_POST_STATUS_OPTIONS: Array<{ value: XpRobotForwardPostStatus; label: string }> = [
  { value: 'published', label: '已发布（审核通过）' },
  { value: 'pending_review', label: '待审核' },
  { value: 'rejected', label: '已驳回' },
  { value: 'hidden', label: '已隐藏' },
  { value: 'deleted', label: '已删除' },
]

export type XpRobotForwardTaskStatus = 'published' | 'draft' | 'pending_review' | 'rejected' | 'offline' | 'archived'

export const XP_ROBOT_FORWARD_TASK_STATUS_OPTIONS: Array<{ value: XpRobotForwardTaskStatus; label: string }> = [
  { value: 'published', label: '已发布（进行中）' },
  { value: 'draft', label: '草稿' },
  { value: 'pending_review', label: '待审核' },
  { value: 'rejected', label: '已驳回' },
  { value: 'offline', label: '已下架' },
  { value: 'archived', label: '已归档' },
]

export interface XpRobotTaskCategory {
  code: string
  name: string
}

export interface XpRobotForwardFilterRules {
  content_type: 'community_post' | 'task'
  platforms: string[]
  campus_id: string | null
  campus_name: string | null
  categories: XpRobotForumCategory[]
  post_statuses: XpRobotForwardPostStatus[]
  task_statuses: XpRobotForwardTaskStatus[]
  aggregate_interval: number
  min_messages: number
  max_messages: number
  quiet_start: string | null
  quiet_end: string | null
  active_weekdays: number[]
}

export type XpRobotForwardLinkFormat = 'url_link' | 'short_link'

export const XP_ROBOT_FORWARD_LINK_FORMAT_OPTIONS: Array<{ value: XpRobotForwardLinkFormat; label: string; hint: string }> = [
  { value: 'url_link', label: 'URL Link（https://wxmpurl.cn/...）', hint: '短信、网页、微信内外都能打开；微信内点击会先经过官方 H5 中间页。' },
  { value: 'short_link', label: 'Short Link（mp://...）', hint: '仅微信内有效；群聊里点击直跳小程序页面，更短、不经过中间页。' },
]

export interface XpRobotForwardTemplateConfig {
  template: string
  footer?: string | null
  keywords: string[]
  content_max_length: number
  link_format?: XpRobotForwardLinkFormat
}

export interface XpRobotForwardConfig {
  id: string
  name: string
  description: string | null
  enabled: boolean
  filter_rules: XpRobotForwardFilterRules
  template_config: XpRobotForwardTemplateConfig
  robot_targets: XpRobotForwardTarget[]
  last_forwarded_at: string | null
  stats: { test_count: number; success_count: number; last_tested_at: string | null }
  created_at: string | null
  updated_at: string | null
}

export interface XpRobotForwardConfigInput {
  name: string
  description: string | null
  enabled: boolean
  filter_rules: XpRobotForwardFilterRules
  template_config: XpRobotForwardTemplateConfig
  robot_targets: XpRobotForwardTarget[]
}

export function listXpRobotForwardFilterOptions(campusId?: string): Promise<{ schools: XpRobotSchoolOption[]; categories: XpRobotForumCategory[]; task_categories: XpRobotTaskCategory[] }> {
  const query = campusId ? `?campus_id=${encodeURIComponent(campusId)}` : ''
  return adminRequest(`/admin/robot/forwards/filter-options${query}`)
}
export function listXpRobotForwards(filters: { name?: string; enabled?: boolean } = {}): Promise<{ items: XpRobotForwardConfig[] }> {
  const query = new URLSearchParams()
  if (filters.name?.trim()) query.set('name', filters.name.trim())
  if (filters.enabled !== undefined) query.set('enabled', filters.enabled ? '1' : '0')
  const suffix = query.size ? `?${query.toString()}` : ''
  return adminRequest(`/admin/robot/forwards${suffix}`)
}
export function getXpRobotForward(id: string): Promise<XpRobotForwardConfig> {
  return adminRequest(`/admin/robot/forwards/${encodeURIComponent(id)}`)
}
export function createXpRobotForward(input: XpRobotForwardConfigInput): Promise<XpRobotForwardConfig> {
  return adminRequest('/admin/robot/forwards', { method: 'POST', body: JSON.stringify(input) })
}
export function updateXpRobotForward(id: string, input: XpRobotForwardConfigInput): Promise<XpRobotForwardConfig> {
  return adminRequest(`/admin/robot/forwards/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(input) })
}
export function deleteXpRobotForward(id: string): Promise<{ id: string }> {
  return adminRequest(`/admin/robot/forwards/${encodeURIComponent(id)}`, { method: 'DELETE' })
}
export function updateXpRobotForwardStatus(id: string, enabled: boolean): Promise<XpRobotForwardConfig> {
  return adminRequest(`/admin/robot/forwards/${encodeURIComponent(id)}/status`, { method: 'PATCH', body: JSON.stringify({ enabled }) })
}
export function batchUpdateXpRobotForwardStatus(ids: string[], enabled: boolean): Promise<{ items: XpRobotForwardConfig[] }> {
  return adminRequest('/admin/robot/forwards/batch-status', { method: 'POST', body: JSON.stringify({ ids, enabled }) })
}
export function testXpRobotForward(id: string, input: { target_id: string; group_id: string; post_count?: number }, idempotencyKey: string): Promise<XpRobotMessage> {
  return adminRequest(`/admin/robot/forwards/${encodeURIComponent(id)}/test`, {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: JSON.stringify(input),
  })
}

export type XpRobotPostContentSource = 'pool' | 'history' | 'ai'

export interface XpRobotPostBot {
  id: string
  name: string
  enabled: boolean
  campus_id: string | null
  campus_name: string | null
  category_code: string
  category_name: string
  content_source: XpRobotPostContentSource
  posts_per_day: number
  posts_per_run: number
  posted_today: number
  active_weekdays: number[]
  active_start: string
  active_end: string
  min_gap_minutes: number
  history_min_likes: number
  ai_prompt: string | null
  last_posted_at: string | null
  last_error: string | null
  templates_total: number
  templates_available: number
  created_at?: string | null
  updated_at?: string | null
}

export interface XpRobotPostBotInput {
  name: string
  enabled: boolean
  campus_id: string
  category_code: string
  content_source: XpRobotPostContentSource
  posts_per_day: number
  posts_per_run: number
  active_weekdays: number[]
  active_start: string
  active_end: string
  min_gap_minutes: number
  history_min_likes: number
  ai_prompt?: string | null
}

export interface XpRobotPostTemplate {
  id: string
  title: string
  content: string
  enabled: boolean
  sort: number
  used_at: string | null
  updated_at: string | null
}

export function listXpRobotPostBots(keyword?: string): Promise<{ items: XpRobotPostBot[] }> {
  const query = keyword?.trim() ? `?keyword=${encodeURIComponent(keyword.trim())}` : ''
  return adminRequest(`/admin/robot/post-bots${query}`)
}
export function createXpRobotPostBot(input: XpRobotPostBotInput): Promise<XpRobotPostBot> {
  return adminRequest('/admin/robot/post-bots', { method: 'POST', body: JSON.stringify(input) })
}
export function updateXpRobotPostBot(id: string, input: XpRobotPostBotInput): Promise<XpRobotPostBot> {
  return adminRequest(`/admin/robot/post-bots/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(input) })
}
export function updateXpRobotPostBotStatus(id: string, enabled: boolean): Promise<XpRobotPostBot> {
  return adminRequest(`/admin/robot/post-bots/${encodeURIComponent(id)}/status`, { method: 'PATCH', body: JSON.stringify({ enabled }) })
}
export function deleteXpRobotPostBot(id: string): Promise<{ deleted: boolean }> {
  return adminRequest(`/admin/robot/post-bots/${encodeURIComponent(id)}`, { method: 'DELETE' })
}
export function publishXpRobotPostBotNow(id: string): Promise<{ post_id: string; title: string; status: string }> {
  return adminRequest(`/admin/robot/post-bots/${encodeURIComponent(id)}/publish`, { method: 'POST' })
}
export function listXpRobotPostTemplates(botId: string): Promise<{ items: XpRobotPostTemplate[] }> {
  return adminRequest(`/admin/robot/post-bots/${encodeURIComponent(botId)}/templates`)
}
export function createXpRobotPostTemplate(botId: string, input: { title: string; content: string; enabled: boolean; sort: number }): Promise<XpRobotPostTemplate> {
  return adminRequest(`/admin/robot/post-bots/${encodeURIComponent(botId)}/templates`, { method: 'POST', body: JSON.stringify(input) })
}
export function updateXpRobotPostTemplate(botId: string, templateId: string, input: { title: string; content: string; enabled: boolean; sort: number }): Promise<XpRobotPostTemplate> {
  return adminRequest(`/admin/robot/post-bots/${encodeURIComponent(botId)}/templates/${encodeURIComponent(templateId)}`, { method: 'PUT', body: JSON.stringify(input) })
}
export function deleteXpRobotPostTemplate(botId: string, templateId: string): Promise<{ deleted: boolean }> {
  return adminRequest(`/admin/robot/post-bots/${encodeURIComponent(botId)}/templates/${encodeURIComponent(templateId)}`, { method: 'DELETE' })
}

export interface XpRobotAssistantBinding {
  binding_id: string
  device_id: string
  device_name: string | null
  wxid: string
  account_name: string | null
  bound_at: string
}

export interface XpRobotAssistant {
  id: string
  name: string
  remark: string | null
  enabled: boolean
  app_id: string
  app_secret_masked: string | null
  robot_bindings: XpRobotAssistantBinding[]
  robot_count: number
  created_at: string | null
  updated_at: string | null
}

export interface XpRobotAssistantInput {
  name: string
  remark: string | null
  enabled: boolean
}

export interface XpRobotAssistantWebhook {
  assistant_id: string
  assistant_name: string
  app_id: string
  url: string
  binding_count: number
  last_tested_at: string | null
  last_test_status: 'LOCAL_READY' | 'LOCAL_FAILED' | null
  last_test_message: string | null
  notice: string
}

export function listXpRobotAssistants(filters: { name?: string; app_id?: string; enabled?: boolean } = {}): Promise<{ items: XpRobotAssistant[] }> {
  const query = new URLSearchParams()
  if (filters.name?.trim()) query.set('name', filters.name.trim())
  if (filters.app_id?.trim()) query.set('app_id', filters.app_id.trim())
  if (filters.enabled !== undefined) query.set('enabled', filters.enabled ? '1' : '0')
  const suffix = query.size ? `?${query.toString()}` : ''
  return adminRequest(`/admin/robot/assistants${suffix}`)
}
export function createXpRobotAssistant(input: XpRobotAssistantInput): Promise<XpRobotAssistant & { app_secret_once: string }> {
  return adminRequest('/admin/robot/assistants', { method: 'POST', body: JSON.stringify(input) })
}
export function updateXpRobotAssistant(id: string, input: Omit<XpRobotAssistantInput, 'enabled'>): Promise<XpRobotAssistant> {
  return adminRequest(`/admin/robot/assistants/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(input) })
}
export function deleteXpRobotAssistant(id: string): Promise<{ id: string }> {
  return adminRequest(`/admin/robot/assistants/${encodeURIComponent(id)}`, { method: 'DELETE' })
}
export function updateXpRobotAssistantStatus(id: string, enabled: boolean): Promise<XpRobotAssistant> {
  return adminRequest(`/admin/robot/assistants/${encodeURIComponent(id)}/status`, { method: 'PATCH', body: JSON.stringify({ enabled }) })
}
export function bindXpRobotAssistant(id: string, input: { device_id: string; device_name?: string | null; wxid: string; account_name?: string | null }): Promise<XpRobotAssistant> {
  return adminRequest(`/admin/robot/assistants/${encodeURIComponent(id)}/bindings`, { method: 'POST', body: JSON.stringify(input) })
}
export function unbindXpRobotAssistant(id: string, bindingId: string): Promise<XpRobotAssistant> {
  return adminRequest(`/admin/robot/assistants/${encodeURIComponent(id)}/bindings/${encodeURIComponent(bindingId)}`, { method: 'DELETE' })
}
export function getXpRobotAssistantWebhook(id: string): Promise<XpRobotAssistantWebhook> {
  return adminRequest(`/admin/robot/assistants/${encodeURIComponent(id)}/webhook`)
}
export function testXpRobotAssistantWebhook(id: string): Promise<XpRobotAssistantWebhook> {
  return adminRequest(`/admin/robot/assistants/${encodeURIComponent(id)}/webhook/test`, { method: 'POST', body: JSON.stringify({}) })
}

// ---------------------------------------------------------------------------
// 快递代取：订单、跑腿、结算与校区小票机
// ---------------------------------------------------------------------------

export type ExpressPickupOrderStatus = 'pending_payment' | 'pending_accept' | 'accepted' | 'picked_up' | 'delivered' | 'completed' | 'cancelled'

export const EXPRESS_PICKUP_STATUS_OPTIONS: Array<{ value: ExpressPickupOrderStatus; label: string; color: string }> = [
  { value: 'pending_payment', label: '待支付', color: 'default' },
  { value: 'pending_accept', label: '待接单', color: 'processing' },
  { value: 'accepted', label: '已接单', color: 'blue' },
  { value: 'picked_up', label: '已取件', color: 'cyan' },
  { value: 'delivered', label: '已送达', color: 'gold' },
  { value: 'completed', label: '已完成', color: 'success' },
  { value: 'cancelled', label: '已取消', color: 'default' },
]

export interface ExpressPickupOrderEvent {
  from_status: string | null
  to_status: string
  event: string
  actor_type?: string
  reason: string | null
  created_at: string | null
}

export interface ExpressPickupPrintJob {
  id: string
  status: string
  error_message: string | null
  printed_at: string | null
}

export interface ExpressPickupOrderAdmin {
  id: string
  order_no: string
  status: ExpressPickupOrderStatus
  campus_id: number
  pickup_site: string
  pickup_code: string
  package_info: string | null
  package_count: number
  deliver_building: string
  deliver_address: string
  contact_name: string
  contact_phone: string
  reward_cents: number
  total_cents: number
  payment_method: string | null
  paid_at: string | null
  expected_at: string | null
  remark: string | null
  runner_user_id: string | null
  runner_name: string | null
  accepted_at: string | null
  picked_up_at: string | null
  delivered_at: string | null
  completed_at: string | null
  cancelled_at: string | null
  cancel_reason: string | null
  print_status: string | null
  printed_at: string | null
  created_at: string | null
  events?: ExpressPickupOrderEvent[]
  print_jobs?: ExpressPickupPrintJob[]
}

export interface ExpressPickupPagination {
  page: number
  page_size: number
  total: number
  last_page: number
}

export interface ExpressPickupRunnerAdmin {
  id: string
  campus_id: number
  status: 'pending' | 'active' | 'rejected' | 'suspended'
  real_name: string
  phone: string
  applied_at: string | null
  reviewed_at: string | null
  reject_reason: string | null
  user: { id: string; display_name: string } | null
  created_at: string | null
}

export interface ExpressPickupSettlementItemAdmin {
  id: number
  runner_user_id: number
  order_count: number
  amount_cents: number
  status: string
  paid_at: string | null
}

export interface ExpressPickupSettlementAdmin {
  id: string
  batch_no: string
  campus_id: number | null
  period_start: string
  period_end: string
  status: 'draft' | 'pending' | 'paid'
  total_cents: number
  runner_count: number
  order_count: number
  confirmed_at: string | null
  paid_at: string | null
  created_at: string | null
  items: ExpressPickupSettlementItemAdmin[]
}

export interface CampusPrinterVendorOption {
  code: 'feie' | 'daqu' | 'shangpeng'
  label: string
  ready: boolean
}

export interface CampusPrinterVariable {
  key: string
  label: string
}

export interface CampusPrinter {
  id: string
  campus_id: number
  name: string
  vendor: 'feie' | 'daqu' | 'shangpeng'
  vendor_label: string
  sn: string
  username: string | null
  secret_configured: boolean
  paper_width: number
  template: string | null
  status: 'enabled' | 'disabled'
  last_test_at: string | null
  last_test_status: string | null
  last_test_message: string | null
  created_at: string | null
  updated_at: string | null
}

export interface CampusPrinterList {
  items: CampusPrinter[]
  vendors: CampusPrinterVendorOption[]
  variables: CampusPrinterVariable[]
  default_template: string
}

export interface CampusPrinterInput {
  campus_id?: number | null
  name: string
  vendor: CampusPrinter['vendor']
  sn: string
  username?: string | null
  secret?: string | null
  clear_secret?: boolean
  paper_width?: number
  template?: string | null
  status?: CampusPrinter['status']
}

function expressQuery(filters: Record<string, unknown>): string {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return
    query.set(key, String(value))
  })
  return query.size ? `?${query.toString()}` : ''
}

export function listExpressPickupOrders(filters: Record<string, unknown> = {}): Promise<{ items: ExpressPickupOrderAdmin[]; pagination: ExpressPickupPagination }> {
  return adminRequest(`/admin/express-pickup/orders${expressQuery(filters)}`)
}
export function getExpressPickupOrder(id: string): Promise<ExpressPickupOrderAdmin> {
  return adminRequest(`/admin/express-pickup/orders/${encodeURIComponent(id)}`)
}
export function cancelExpressPickupOrder(id: string, reason: string): Promise<ExpressPickupOrderAdmin> {
  return adminRequest(`/admin/express-pickup/orders/${encodeURIComponent(id)}/cancel`, { method: 'POST', body: JSON.stringify({ reason }) })
}
export function reprintExpressPickupOrder(id: string): Promise<{ job_id: string; status: string }> {
  return adminRequest(`/admin/express-pickup/orders/${encodeURIComponent(id)}/reprint`, { method: 'POST', body: JSON.stringify({}) })
}

export function listExpressPickupRunners(filters: Record<string, unknown> = {}): Promise<{ items: ExpressPickupRunnerAdmin[]; pagination: ExpressPickupPagination }> {
  return adminRequest(`/admin/express-pickup/runners${expressQuery(filters)}`)
}
export function reviewExpressPickupRunner(id: string, input: { status: 'active' | 'rejected' | 'suspended'; reason?: string }): Promise<ExpressPickupRunnerAdmin> {
  return adminRequest(`/admin/express-pickup/runners/${encodeURIComponent(id)}/review`, { method: 'POST', body: JSON.stringify(input) })
}

export function listExpressPickupSettlements(filters: Record<string, unknown> = {}): Promise<{ items: ExpressPickupSettlementAdmin[] }> {
  return adminRequest(`/admin/express-pickup/settlements${expressQuery(filters)}`)
}
export function createExpressPickupSettlement(input: { period_start: string; period_end: string }): Promise<ExpressPickupSettlementAdmin> {
  return adminRequest('/admin/express-pickup/settlements', { method: 'POST', body: JSON.stringify(input) })
}
export function confirmExpressPickupSettlement(id: string): Promise<ExpressPickupSettlementAdmin> {
  return adminRequest(`/admin/express-pickup/settlements/${encodeURIComponent(id)}/confirm`, { method: 'POST', body: JSON.stringify({}) })
}
export function payExpressPickupSettlement(id: string): Promise<ExpressPickupSettlementAdmin> {
  return adminRequest(`/admin/express-pickup/settlements/${encodeURIComponent(id)}/pay`, { method: 'POST', body: JSON.stringify({}) })
}

export function listCampusPrinters(campusId?: number | null): Promise<CampusPrinterList> {
  return adminRequest(`/admin/express-pickup/printers${expressQuery({ campus_id: campusId ?? '' })}`)
}
export function createCampusPrinter(input: CampusPrinterInput): Promise<CampusPrinter> {
  return adminRequest('/admin/express-pickup/printers', { method: 'POST', body: JSON.stringify(input) })
}
export function updateCampusPrinter(id: string, input: CampusPrinterInput): Promise<CampusPrinter> {
  return adminRequest(`/admin/express-pickup/printers/${encodeURIComponent(id)}`, { method: 'PUT', body: JSON.stringify(input) })
}
export function deleteCampusPrinter(id: string): Promise<{ id: string }> {
  return adminRequest(`/admin/express-pickup/printers/${encodeURIComponent(id)}`, { method: 'DELETE' })
}
export function testCampusPrinter(id: string): Promise<{ ok: boolean; message: string; printer: CampusPrinter }> {
  return adminRequest(`/admin/express-pickup/printers/${encodeURIComponent(id)}/test`, { method: 'POST', body: JSON.stringify({}) })
}
export function previewCampusPrinter(input: { template?: string | null; paper_width?: number; campus_id?: number | null }): Promise<{ content: string }> {
  return adminRequest('/admin/express-pickup/printers/preview', { method: 'POST', body: JSON.stringify(input) })
}
