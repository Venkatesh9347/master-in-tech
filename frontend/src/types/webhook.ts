export const WEBHOOK_EVENTS = [
  'payment.paid',
  'payment.refunded',
  'enrollment.created',
  'certificate.issued',
] as const;

export type WebhookEvent = (typeof WEBHOOK_EVENTS)[number];

export type WebhookDeliveryStatus = 'pending' | 'delivered' | 'failed' | 'dead';

export interface WebhookSubscription {
  id: number;
  target_url: string;
  events: string[];
  is_active: boolean;
  failed_deliveries: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface WebhookDelivery {
  id: number;
  webhook_subscription_id: number;
  event: string;
  status: string;
  attempts: number;
  next_retry_at: string | null;
  delivered_at: string | null;
  last_error: string | null;
  created_at: string | null;
}
