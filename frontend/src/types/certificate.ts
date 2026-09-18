/**
 * Admin certificate revocation console contracts (mirror the backend safe
 * projections in CertificateController::adminCertificatePayload; the revoke
 * response reuses CertificateController::revocationPayload).
 *
 * The numeric `id` is the revoke target — never the certificate code.
 */
export interface CertificateListItem {
  id: number
  certificate_code: string
  status: string
  issued_at: string | null
  revoked_at: string | null
  revoked_by: number | null
  revocation_reason: string | null
  user: { id: number; name: string; email: string } | null
  course: { id: number; title: string } | null
  created_at: string | null
}

export interface CertificateDetail extends CertificateListItem {
  revoked_by_user: { id: number; name: string } | null
}

export interface CertificateRevocation {
  certificate_code: string
  status: string
  revoked_at: string | null
  revoked_by: number | null
  revocation_reason: string | null
}

export const CERTIFICATE_STATUS_FILTERS = ['all', 'active', 'revoked'] as const

export type CertificateStatusFilter = (typeof CERTIFICATE_STATUS_FILTERS)[number]

export const CERTIFICATE_REASON_MIN_LENGTH = 10
export const CERTIFICATE_REASON_MAX_LENGTH = 500

export const CERTIFICATE_ERROR_MESSAGES: Record<string, string> = {
  certificate_not_found: 'Certificate not found. It may have been deleted with its user or course.',
  already_revoked: 'This certificate has already been revoked.',
  permission_denied: "You don't have permission to revoke certificates.",
  session_expired: 'Your session has expired. Please log in again.',
  validation_failed: 'Provide a revocation reason of 10–500 characters.',
  load_failed: 'Failed to load certificates.',
  detail_failed: 'Failed to load certificate details.',
  revoke_failed: 'Certificate could not be revoked.',
}
