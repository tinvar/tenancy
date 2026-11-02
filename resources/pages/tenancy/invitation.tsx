import { Banner, Card, Heading, Stack, Text } from '@meridian/ui';
import { Title } from '@tinvar/react/head';
import { AcceptInviteForm } from '../../components/tenancy/TenancyForms';
import type { InvitationPageData } from '../../components/tenancy/types';

/**
 * The page an invite's emailed link lands on (ADR 0161): whom it invites, to
 * what, and -- signed in as that address -- the button that accepts it.
 */
export default function Page({ data }: { data: InvitationPageData }) {
  if (!data.open) {
    return (
      <Stack gap="s6">
        <Title>Invite</Title>
        <Banner tone="bad" title="This invite can't be used">
          It has expired, was revoked or was used already. Ask whoever invited you for a new one.
        </Banner>
      </Stack>
    );
  }

  return (
    <Stack gap="s6">
      <Title>{`Join ${data.organization}`}</Title>
      <Stack gap="s2">
        <Heading level={1} size="title1">
          {`Join ${data.organization}`}
        </Heading>
        <Text tone="secondary">{`${data.email} is invited as ${data.role}.`}</Text>
      </Stack>
      <Card variant="flat">
        {!data.accountExists ? (
          <Banner tone="info" title="Make an account first">
            {`Create an account with ${data.email}, then open this link again to join.`}
          </Banner>
        ) : !data.signedInAsInvitee ? (
          <Banner tone="info" title="Sign in to accept">
            {`This invite is for ${data.email}. Sign in as that account, then open this link again.`}
          </Banner>
        ) : (
          <AcceptInviteForm action={data.acceptAction} token={data.token} />
        )}
      </Card>
    </Stack>
  );
}
