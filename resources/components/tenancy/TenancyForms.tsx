'use client';

import { useState, type ReactNode } from 'react';
import { Banner, Button, Field, Input, Select } from '@meridian/ui';
import { Form, type HttpFailure } from '@tinvar/react/form';
import { usePoll } from '@tinvar/react/poll';
import type { TenantRole } from './types';

/**
 * The tenant self-service forms (ADR 0155). Each is a real form posting to
 * its action, by the name the page's data gives it, so it works before
 * script loads and in whichever class the application declared the page:
 * a refusal comes back as the page with its message on the field. Once
 * hydrated, each submits in place.
 */

/** What went wrong that is no field's fault, in words that say what to do. */
function describe(failure: HttpFailure | null): string {
  if (failure === null) {
    return 'Nothing was sent. Check your connection and try again.';
  }
  if (failure.status === 403) {
    return 'Your role does not allow this. Ask an owner of your organization.';
  }
  if (failure.status === 404) {
    return 'That is no longer there. Reload the page to see where things stand.';
  }
  if (failure.status === 429) {
    return 'Too many attempts. Wait a minute, then try again.';
  }
  return `Something went wrong. Try again.${failure.reference ? ` Quote reference ${failure.reference} if it keeps happening.` : ''}`;
}

/** A form with a banner for whatever is no field's fault. */
function TenancyForm({
  action,
  label,
  children,
}: {
  action: string;
  label: string;
  children: (slot: { errors: Record<string, string>; processing: boolean; old: (field: string) => string }) => ReactNode;
}) {
  const [failure, setFailure] = useState<string | null>(null);

  return (
    <Form
      noValidate
      aria-label={label}
      action={action}
      onStart={() => setFailure(null)}
      onHttpException={(f) => setFailure(describe(f))}
      onNetworkError={() => setFailure(describe(null))}
    >
      {(slot) => (
        <>
          {failure && (
            <Banner tone="bad" title="That didn't work">
              {failure}
            </Banner>
          )}
          {slot.expired && (
            <Banner tone="warn" title="This page has expired">
              It was open longer than a session lasts, so it has been loaded again. Submit it once more.
            </Banner>
          )}
          {children({ errors: slot.errors, processing: slot.processing, old: (field) => slot.old(field, '') ?? '' })}
        </>
      )}
    </Form>
  );
}

/** One button, posting one value: a switch, a check, a removal, a revocation. */
export function ActionButton({
  action,
  field,
  value,
  label,
  busyLabel,
  variant = 'secondary',
}: {
  action: string;
  field: string;
  value: string;
  label: string;
  busyLabel: string;
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
}) {
  return (
    <TenancyForm action={action} label={label}>
      {({ processing, errors }) => (
        <>
          <input type="hidden" name={field} value={value} />
          {errors[field] && (
            <Banner tone="bad" title="That didn't work">
              {errors[field]}
            </Banner>
          )}
          <Button type="submit" size="sm" variant={variant} disabled={processing}>
            {processing ? busyLabel : label}
          </Button>
        </>
      )}
    </TenancyForm>
  );
}

export function AddDomainForm({ action }: { action: string }) {
  return (
    <TenancyForm action={action} label="Add a domain">
      {({ errors, processing, old }) => (
        <>
          <Field label="Domain" hint="A domain you own, such as shop.example.com." error={errors.host}>
            <Input name="host" placeholder="shop.example.com" defaultValue={old('host')} autoComplete="off" spellCheck={false} required />
          </Field>
          <Button type="submit" variant="primary" disabled={processing}>
            {processing ? 'Adding…' : 'Add domain'}
          </Button>
        </>
      )}
    </TenancyForm>
  );
}

/**
 * Reloads the page every 15 seconds, in the background, while it is drawn:
 * the domains page draws it while a domain is still being proved or failing
 * its checks, so a status the daily check or Caddy's ask changed shows.
 */
export function WatchDomains() {
  usePoll(15_000, {}, { background: 'pause' });

  return null;
}

export function RenameForm({ action, name }: { action: string; name: string }) {
  return (
    <TenancyForm action={action} label="Rename the organization">
      {({ errors, processing }) => (
        <>
          <Field label="Name" error={errors.name}>
            <Input name="name" defaultValue={name} maxLength={100} required />
          </Field>
          <Button type="submit" disabled={processing}>
            {processing ? 'Saving…' : 'Save name'}
          </Button>
        </>
      )}
    </TenancyForm>
  );
}

export function SlugForm({ action, slug }: { action: string; slug: string }) {
  return (
    <TenancyForm action={action} label="Change the address">
      {({ errors, processing }) => (
        <>
          <Field label="Address" hint="Your organization's subdomain. Changing it moves its address: old links stop working." error={errors.slug}>
            <Input name="slug" defaultValue={slug} maxLength={63} autoComplete="off" spellCheck={false} required />
          </Field>
          <Button type="submit" disabled={processing}>
            {processing ? 'Moving…' : 'Change address'}
          </Button>
        </>
      )}
    </TenancyForm>
  );
}

export function InviteForm({ action, roles }: { action: string; roles: TenantRole[] }) {
  return (
    <TenancyForm action={action} label="Invite someone">
      {({ errors, processing, old }) => (
        <>
          <Field label="Email" error={errors.email ? 'Enter a full email address, like name@example.com.' : undefined}>
            <Input type="email" name="email" placeholder="name@example.com" defaultValue={old('email')} required />
          </Field>
          <Field label="Role" error={errors.role}>
            <Select name="role" defaultValue={old('role') || roles[roles.length - 1]?.value}>
              {roles.map((role) => (
                <option key={role.value} value={role.value}>
                  {role.label}
                </option>
              ))}
            </Select>
          </Field>
          <Button type="submit" variant="primary" disabled={processing}>
            {processing ? 'Sending…' : 'Send invite'}
          </Button>
        </>
      )}
    </TenancyForm>
  );
}

export function RoleForm({ action, member, role, roles }: { action: string; member: string; role: string; roles: TenantRole[] }) {
  return (
    <TenancyForm action={action} label="Change role">
      {({ errors, processing }) => (
        <>
          <input type="hidden" name="member" value={member} />
          <Select name="role" defaultValue={role} aria-label="Role" invalid={Boolean(errors.role || errors.member)}>
            {roles.map((r) => (
              <option key={r.value} value={r.value}>
                {r.label}
              </option>
            ))}
          </Select>
          {(errors.role || errors.member) && (
            <Banner tone="bad" title="That didn't work">
              {errors.role || errors.member}
            </Banner>
          )}
          <Button type="submit" size="sm" disabled={processing}>
            {processing ? 'Saving…' : 'Save role'}
          </Button>
        </>
      )}
    </TenancyForm>
  );
}

export function AcceptInviteForm({ action, token }: { action: string; token: string }) {
  return (
    <TenancyForm action={action} label="Accept the invite">
      {({ errors, processing }) => (
        <>
          <input type="hidden" name="token" value={token} />
          {errors.token && (
            <Banner tone="bad" title="This invite can't be accepted">
              {errors.token}
            </Banner>
          )}
          <Button type="submit" variant="primary" disabled={processing}>
            {processing ? 'Joining…' : 'Accept and join'}
          </Button>
        </>
      )}
    </TenancyForm>
  );
}
