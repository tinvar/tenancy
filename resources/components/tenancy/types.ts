/**
 * The data each tenant self-service page sends its view (ADR 0155), as
 * tinvar/tenancy's Data classes declare it. Codegen types an application's
 * own pages as `PageProps<'tenancy/domains'>`; these say the same for the
 * views the kit ships, so they build before codegen has run.
 */

export type TenantSwitchVia = 'current' | 'session' | 'handoff';

export interface TenantChoice {
  tenant: string;
  name: string;
  via: TenantSwitchVia;
  host: string;
}

export interface TenantSwitchPageData {
  tenants: TenantChoice[];
  current: string;
  switchAction: string;
}

export type TenantDomainStatus = 'pending' | 'verified' | 'failing' | 'deactivated';

export interface DnsRecord {
  type: string;
  name: string;
  value: string;
  purpose: 'ownership' | 'pointing' | string;
}

export interface TenantDomain {
  id: string;
  host: string;
  status: TenantDomainStatus;
  reason: string;
  checkedAt: string | null;
  records: DnsRecord[];
}

export interface TenantDomainsPageData {
  domains: TenantDomain[];
  limit: number;
  canManage: boolean;
  addAction: string;
  checkAction: string;
  removeAction: string;
}

export interface TenantMember {
  userId: string;
  name: string;
  email: string;
  role: string;
  you: boolean;
}

export interface TenantInvite {
  id: string;
  email: string;
  role: string;
  expiresAt: string;
}

export interface TenantRole {
  value: string;
  label: string;
}

export interface TenantSettingsActions {
  rename: string;
  changeSlug: string;
  invite: string;
  revokeInvite: string;
  changeRole: string;
  removeMember: string;
}

export interface TenantSettingsPageData {
  name: string;
  slug: string;
  members: TenantMember[];
  invites: TenantInvite[];
  roles: TenantRole[];
  canManageSettings: boolean;
  canManageMembers: boolean;
  actions: TenantSettingsActions;
}

export interface InvitationPageData {
  open: boolean;
  organization: string;
  email: string;
  role: string;
  accountExists: boolean;
  signedInAsInvitee: boolean;
  acceptAction: string;
  token: string;
}
