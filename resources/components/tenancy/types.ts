/**
 * The data each tenant self-service page sends its view (ADR 0161), as
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

/** Why a domain went out of service (ADR 0198). */
export type DomainLapse = 'pointing' | 'ownership' | 'claimed';

export interface TenantDomain {
  id: string;
  host: string;
  status: TenantDomainStatus;
  reason: string;
  checkedAt: string | null;
  records: DnsRecord[];
  /** When the schedule checks it next; null for a pending or released claim. */
  nextCheckAt: string | null;
  /** When "Check now" next looks it up, rather than answering from the last check. */
  checkableAt: string | null;
  /** While failing: the last moment it is served if nothing changes. */
  lapsesAt: string | null;
  /** While deactivated: the moment after which its claim is released. */
  releasesAt: string | null;
  lapse: DomainLapse | null;
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
