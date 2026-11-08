import * as stylex from '@stylexjs/stylex';
import { Badge, Banner, Card, Heading, Stack, Table, TBody, Td, Text, Th, THead, Tr } from '@meridian/ui';
import { space } from '@meridian/ui/tokens.stylex';
import { Title } from '@tinvar/react/head';
import { ActionButton, AddDomainForm, WatchDomains } from '../../components/tenancy/TenancyForms';
import type {
  DomainLapse,
  TenantDomain,
  TenantDomainStatus,
  TenantDomainsPageData,
} from '../../components/tenancy/types';

/** How each status reads, and the tone it is shown in. */
const STATUS: Record<TenantDomainStatus, { label: string; tone: 'neutral' | 'good' | 'warn' | 'bad' }> = {
  pending: { label: 'Pending', tone: 'neutral' },
  verified: { label: 'Verified', tone: 'good' },
  failing: { label: 'Failing', tone: 'warn' },
  deactivated: { label: 'Deactivated', tone: 'bad' },
};

/** Why a deactivated domain went out of service (ADR 0198). */
const LAPSE: Record<DomainLapse, string> = {
  pointing: 'Its DNS stopped pointing here.',
  ownership: 'Its ownership record was missing for too long.',
  claimed: 'Another organization proved it owns this domain.',
};

/** A moment as the server sent it, in UTC to the minute: the same on the server and in the browser. */
function when(iso: string | null): string | null {
  return iso === null ? null : `${iso.slice(0, 10)} ${iso.slice(11, 16)} UTC`;
}

/**
 * The organization's custom domains (ADR 0161): each one's status, what its
 * last check found, when the next is, and the DNS records to create for
 * it. A domain in service is checked again every day, and one whose
 * ownership record goes is failing, then lapses (ADR 0198). While one is
 * pending or failing, the page reloads itself to show what the checks find.
 */
export default function Page({ data }: { data: TenantDomainsPageData }) {
  const watching = data.domains.some((d) => d.status === 'pending' || d.status === 'failing');

  return (
    <Stack gap="s6">
      <Title>Custom domains</Title>
      {watching && <WatchDomains />}
      <Stack gap="s2">
        <Heading level={1} size="title1">
          Custom domains
        </Heading>
        <Text tone="secondary">
          Serve your organization on a domain of your own. Add it, create the two records shown, then check
          it.
          {` Up to ${data.limit} domains.`}
        </Text>
      </Stack>
      {data.domains.length === 0 && (
        <Banner tone="info" title="No domains yet">
          Your organization is served on its own address until you add one.
        </Banner>
      )}
      {data.domains.map((domain) => (
        <DomainCard key={domain.id} domain={domain} data={data} />
      ))}
      {data.canManage && data.domains.length < data.limit && (
        <Card variant="flat" title="Add a domain">
          <AddDomainForm action={data.addAction} />
        </Card>
      )}
    </Stack>
  );
}

function DomainCard({ domain, data }: { domain: TenantDomain; data: TenantDomainsPageData }) {
  const status = STATUS[domain.status];

  return (
    <section aria-label={domain.host} data-domain={domain.host} data-status={domain.status}>
      <Card
        variant="flat"
        title={
          <Stack direction="row" gap="s3" align="center">
            <Text weight={600} mono>
              {domain.host}
            </Text>
            <Badge tone={status.tone}>{status.label}</Badge>
          </Stack>
        }
        description={domain.reason || undefined}
        actions={
          data.canManage ? (
            <Stack direction="row" gap="s2">
              <ActionButton
                action={data.checkAction}
                field="domain"
                value={domain.id}
                label="Check now"
                busyLabel="Checking…"
              />
              <ActionButton
                action={data.removeAction}
                field="domain"
                value={domain.id}
                label="Remove"
                busyLabel="Removing…"
                variant="danger"
              />
            </Stack>
          ) : undefined
        }
      >
        <Stack gap="s1">
          {domain.lapse !== null && <Text tone="secondary">{LAPSE[domain.lapse]}</Text>}
          {domain.lapsesAt !== null && (
            <Text tone="secondary">
              {`Still served until ${when(domain.lapsesAt)}. Fix its records before then, or it stops being served.`}
            </Text>
          )}
          {domain.releasesAt !== null && (
            <Text tone="secondary">
              {`Checked daily until ${when(domain.releasesAt)}; after that your claim on it is released.`}
            </Text>
          )}
          <Text tone="secondary" size="sm">
            {[
              domain.checkedAt !== null ? `Last checked ${when(domain.checkedAt)}.` : 'Not checked yet.',
              domain.nextCheckAt !== null ? `Next check ${when(domain.nextCheckAt)}.` : null,
              domain.checkableAt !== null ? `Check now looks again after ${when(domain.checkableAt)}.` : null,
            ]
              .filter((part) => part !== null)
              .join(' ')}
          </Text>
        </Stack>
        {(domain.status === 'verified' || domain.status === 'failing') && (
          <Text tone="warn" weight={500}>
            Keep the TXT record below published. It is checked every day, and without it this domain stops
            being served.
          </Text>
        )}
        <div {...stylex.props(styles.records)}>
          <Table label={`DNS records for ${domain.host}`}>
            <THead>
              <Tr>
                <Th>Type</Th>
                <Th>Name</Th>
                <Th>Value</Th>
                <Th>For</Th>
              </Tr>
            </THead>
            <TBody>
              {domain.records
                // Once in service, the record that must stay is the one that proves it.
                .filter((record) => domain.status !== 'verified' || record.purpose === 'ownership')
                .map((record) => (
                  <Tr key={`${record.type}:${record.name}:${record.value}`}>
                    <Td mono>{record.type}</Td>
                    <Td mono>{record.name}</Td>
                    <Td mono>{record.value}</Td>
                    <Td muted>
                      {record.purpose === 'ownership' ? 'Proves it is yours' : 'Sends visitors here'}
                    </Td>
                  </Tr>
                ))}
            </TBody>
          </Table>
        </div>
      </Card>
    </section>
  );
}

const styles = stylex.create({
  records: { marginTop: space.s2 },
});
