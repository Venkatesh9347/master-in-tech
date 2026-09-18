/**
 * Admin refund console contracts (mirror the backend safe projections in
 * AdminPaymentController; amounts are integer paise).
 */
export interface RefundListItem {
  id: number
  provider: string
  order_id: string | null
  payment_id: string | null
  user: { id: number; name: string; email: string } | null
  course: { id: number; title: string } | null
  amount: number
  currency: string
  status: string
  refunded_amount: number
  remaining_refundable: number
  paid_at: string | null
  created_at: string | null
}

export interface RefundRecord {
  id: number
  payment_transaction_id: number
  provider: string
  provider_refund_id: string | null
  amount: number
  currency: string
  status: string
  source: string
  created_at: string | null
}

export interface RefundDetail extends RefundListItem {
  refunds: RefundRecord[]
}

export type RefundErrorCode =
  | 'amount_exceeds_remaining'
  | 'already_refunded'
  | 'not_refundable'
  | 'invalid_amount'
  | 'payment_not_found'
  | 'idempotency_key_conflict'
  | 'provider_failed'
  | 'provider_not_configured'

export const REFUND_ERROR_MESSAGES: Record<RefundErrorCode, string> = {
  amount_exceeds_remaining: 'Amount exceeds the remaining refundable balance.',
  already_refunded: 'This payment has already been fully refunded.',
  not_refundable: 'This payment cannot be refunded (it was never captured).',
  invalid_amount: 'Enter an amount greater than zero.',
  payment_not_found: 'Payment record not found.',
  idempotency_key_conflict: 'A different refund is already recorded for this request.',
  provider_failed: 'The payment provider rejected the refund. It is safe to retry.',
  provider_not_configured: 'The payment provider is not configured.',
}

/** Format integer paise using the transaction currency (INR paise by default). */
export function formatPaise(amountPaise: number, currency = 'INR'): string {
  const major = amountPaise / 100

  try {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency }).format(major)
  } catch {
    return `${major.toFixed(2)} ${currency}`
  }
}
