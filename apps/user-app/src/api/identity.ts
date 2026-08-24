import { apiRequest } from '@/api/http'

export interface IdentityVerificationStatus {
  enabled: boolean
  driver: 'disabled' | 'manual' | 'aliyun'
  status: 'not_submitted' | 'pending' | 'verified' | 'failed' | 'provider_error'
  real_name_masked: string | null
  identity_number_masked: string | null
  verified_at: string | null
}

export function loadIdentityVerification(token: string) {
  return apiRequest<IdentityVerificationStatus>({ path: '/me/identity-verification', accessToken: token })
}

export function submitIdentityVerification(token: string, realName: string, identityNumber: string) {
  return apiRequest<IdentityVerificationStatus>({
    path: '/me/identity-verification', method: 'POST', accessToken: token,
    data: {
      real_name: realName,
      identity_number: identityNumber,
      personal_information_consent: true,
    },
  })
}
