import * as stylex from '@stylexjs/stylex';
import { Badge, Banner, Card, Heading, Stack, Table, TBody, Td, Text, Th, THead, Tr } from '@meridian/ui';
import { space } from '@meridian/ui/tokens.stylex';
import { Title } from '@tinvar/react/head';
import { ActionButton, AddDomainForm, WatchDomains } from '../../components/tenancy/TenancyForms';
import type { TenantDomain, TenantDomainStatus, TenantDomainsPageData } from '../../components/tenancy/types';

/** How each status reads, and the tone it is shown in. */
const STATUS: Record<TenantDomainStatus, { label: string; tone: 'neutral' | 'good' | 'warn' | 'bad' }> = {
  pending: { label: 'Pending', tone: 'neutral' },
  verified: { label: 'Verified', tone: 'good' },
  failing: { label: 'Failing', tone: 'warn' },
  deactivated: { label: 'Deactivated', tone: 'bad' },
};

/**
 * The organization's custom domains (ADR 0159): each one's status, what its
 * last check found, and the DNS records to create for it. While one is
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
          Serve your organization on a domain of your own. Add it, create the two records shown, then check it.
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
              <ActionButton action={data.checkAction} field="domain" value={domain.id} label="Check now" busyLabel="Checking…" />
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
        {domain.status !== 'verified' && (
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
                {domain.records.map((record) => (
                  <Tr key={`${record.type}:${record.name}:${record.value}`}>
                    <Td mono>{record.type}</Td>
                    <Td mono>{record.name}</Td>
                    <Td mono>{record.value}</Td>
                    <Td muted>{record.purpose === 'ownership' ? 'Proves it is yours' : 'Sends visitors here'}</Td>
                  </Tr>
                ))}
              </TBody>
            </Table>
          </div>
        )}
      </Card>
    </section>
  );
}

const styles = stylex.create({
  records: { marginTop: space.s2 },
});
