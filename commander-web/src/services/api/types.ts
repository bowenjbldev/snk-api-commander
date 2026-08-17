// Forma de las entidades del control plane, tal y como las devuelve el mock.
// Espejo de `commander_*` en la base `commander`; nombres de campo idénticos a la API real.

export type ProvisioningStatus =
  | 'pending'
  | 'provisioning'
  | 'ready'
  | 'failed'
  | 'deprovisioning'
  | 'deprovisioned';

export type ServiceStatus = 'active' | 'grace' | 'suspended';
export type EntityStatus = 'active' | 'inactive' | 'suspended';
export type SubscriptionStatus = 'trialing' | 'active' | 'past_due' | 'canceled';
export type InvoiceStatus = 'draft' | 'pending' | 'paid' | 'overdue' | 'void';
export type QuotaAction = 'none' | 'warn' | 'suspend' | 'recommend';
export type CodeStatus = 'available' | 'missing';
export type SubscriptionMode = 'catalog' | 'negotiated';

export interface Account {
  id: number;
  name: string;
  slug: string;
  status: EntityStatus;
  email: string;
  billing_email: string;
  tax_id: string;
  payment_terms: string;
  country: string;
  address: string;
  phone: string;
  notes: string;
  created_at: string;
  updated_at: string;
}

export interface Client {
  id: number;
  account_id: number;
  name: string;
  slug: string;
  email: string;
  status: EntityStatus;
  notes: string;
  created_at: string;
  updated_at: string;
}

export interface ApplicationMetric {
  key: string;
  name: string;
  unit: string;
  window: string;
  kind: string;
  meter: string;
  status: string;
}

export interface Application {
  id: number;
  name: string;
  slug: string;
  package_name: string;
  description: string;
  status: EntityStatus;
  code_status: CodeStatus;
  metrics: ApplicationMetric[];
  created_at: string;
  updated_at: string;
}

export interface PlanLimit {
  metric_key: string;
  limit_value: number | null;
  warning_percent: number | null;
  action: QuotaAction;
  consecutive_periods: number | null;
}

export interface Plan {
  id: number;
  application_id: number;
  name: string;
  slug: string;
  description: string;
  monthly_price: number;
  currency: string;
  status: EntityStatus;
  limits: PlanLimit[];
  created_at: string;
  updated_at: string;
}

export interface Subscription {
  id: number;
  account_id: number;
  application_id: number;
  name: string;
  mode: SubscriptionMode;
  status: SubscriptionStatus;
  monthly_amount: number;
  currency: string;
  max_instances: number;
  trial_ends_at: string | null;
  starts_at: string;
  ends_at: string | null;
  notes: string;
  created_at: string;
  updated_at: string;
}

export interface SubscriptionItem {
  id: number;
  subscription_id: number;
  client_id: number;
  instance_id: number;
  plan_id: number | null;
  status: 'active' | 'terminated';
  started_at: string;
  ended_at: string | null;
}

export interface Instance {
  id: number;
  client_id: number;
  account_id: number;
  application_id: number;
  plan_id: number | null;
  subscription_id: number | null;
  database_name: string;
  database_user: string;
  provisioning_status: ProvisioningStatus;
  service_status: ServiceStatus;
  status_reason: string | null;
  status_message: string | null;
  grace_until: string | null;
  last_error: string | null;
  requests_24h: number;
  last_activity_at: string | null;
  created_at: string;
  updated_at: string;
  provisioned_at: string | null;
}

export interface Quota {
  id: number;
  scope: 'instance' | 'subscription';
  instance_id: number | null;
  subscription_id: number | null;
  account_id: number;
  application_id: number;
  metric_key: string;
  limit_value: number | null;
  current_value: number | null;
  warning_percent: number | null;
  action: QuotaAction;
  consecutive_periods: number | null;
  consecutive_count: number;
  last_warning_at: string | null;
  measured_at: string;
}

export interface Measurement {
  date: string;
  value: number;
}

export interface InvoiceLine {
  subscription_id: number;
  item_id: number | null;
  instance_id: number | null;
  description: string;
  quantity: number;
  unit_price: number;
  amount: number;
}

export interface Invoice {
  id: number;
  number: string;
  account_id: number;
  status: InvoiceStatus;
  issued_at: string;
  due_at: string;
  currency: string;
  lines: InvoiceLine[];
  total: number;
  created_at: string;
  updated_at: string;
}

export interface Activity {
  id: number;
  type: string;
  label: string;
  instance_id: number | null;
  subscription_id: number | null;
  invoice_id: number | null;
  account_id: number;
  reason: string;
  message: string;
  created_at: string;
}

export interface LogEntry {
  id: number;
  instance_id: number;
  account_id: number;
  application_id: number;
  method: string;
  path: string;
  status: number;
  duration_ms: number;
  actor: string;
  ip: string;
  bytes: number;
  memory_mb: number;
  created_at: string;
}

export interface UsageBucket {
  instance_id: number;
  bucket: string;
  requests: number;
  e4xx: number;
  e5xx: number;
}

export interface Operator {
  id: number;
  name: string;
  email: string;
  short_name: string;
  company: string;
  phone: string;
  role: string;
  created_at: string;
}

export interface Settings {
  operator_name: string;
  operator_email: string;
  default_grace_hours: number;
  notify_suspensions: boolean;
  notify_overdue_invoices: boolean;
}

export interface Store {
  accounts: Account[];
  clients: Client[];
  applications: Application[];
  plans: Plan[];
  subscriptions: Subscription[];
  items: SubscriptionItem[];
  instances: Instance[];
  quotas: Quota[];
  measurements: Record<number, Measurement[]>;
  invoices: Invoice[];
  activity: Activity[];
  logs: LogEntry[];
  usage: UsageBucket[];
  operators: Operator[];
  settings: Settings;
  seq: Record<string, number>;
}
