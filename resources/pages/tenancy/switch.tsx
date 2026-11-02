import * as stylex from '@stylexjs/stylex';
import { Badge, Card, Heading, Stack, Text } from '@meridian/ui';
import { space } from '@meridian/ui/tokens.stylex';
import { Title } from '@tinvar/react/head';
import { ActionButton } from '../../components/tenancy/TenancyForms';
import type { TenantSwitchPageData } from '../../components/tenancy/types';

/**
 * The tenant switcher (ADR 0161): every organization the signed-in user
 * belongs to, the current one first. One on a host of its own is reached
 * through the handoff, which signs them in there; one on this host by a new
 * session.
 */
export default function Page({ data }: { data: TenantSwitchPageData }) {
  return (
    <Stack gap="s6">
      <Title>Your organizations</Title>
      <Stack gap="s2">
        <Heading level={1} size="title1">
          Your organizations
        </Heading>
        <Text tone="secondary">Switch to another organization you belong to.</Text>
      </Stack>
      <Card variant="flat" flush>
        <ul aria-label="Organizations" {...stylex.props(styles.list)}>
          {data.tenants.map((choice) => (
            <li key={choice.tenant} data-tenant={choice.tenant} {...stylex.props(styles.row)}>
              <Stack direction="row" justify="between" align="center" gap="s4">
                <Stack gap="s1">
                  <Text weight={600}>{choice.name}</Text>
                  {choice.host && (
                    <Text size="sm" tone="muted" mono>
                      {choice.host}
                    </Text>
                  )}
                </Stack>
                {choice.via === 'current' ? (
                  <Badge tone="good">Current</Badge>
                ) : (
                  <ActionButton
                    action={data.switchAction}
                    field="tenant"
                    value={choice.tenant}
                    label={`Switch to ${choice.name}`}
                    busyLabel="Switching…"
                  />
                )}
              </Stack>
            </li>
          ))}
        </ul>
      </Card>
    </Stack>
  );
}

const styles = stylex.create({
  list: { listStyle: 'none', margin: 0, padding: 0 },
  row: { paddingBlock: space.s3, paddingInline: space.s4 },
});
