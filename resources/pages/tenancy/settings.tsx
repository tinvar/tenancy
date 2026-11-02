import { Badge, Card, Heading, Stack, Table, TBody, Td, Text, Th, THead, Tr } from '@meridian/ui';
import { Title } from '@tinvar/react/head';
import {
  ActionButton,
  InviteForm,
  RenameForm,
  RoleForm,
  SlugForm,
} from '../../components/tenancy/TenancyForms';
import type { TenantRole, TenantSettingsPageData } from '../../components/tenancy/types';

/**
 * The organization's settings (ADR 0161): its name and address, its members
 * and their roles, and the invites still open. Each form is drawn for a
 * member whose role may use it; the action behind it asks again.
 */
export default function Page({ data }: { data: TenantSettingsPageData }) {
  const label = (value: string) => data.roles.find((r: TenantRole) => r.value === value)?.label ?? value;

  return (
    <Stack gap="s6">
      <Title>{`${data.name}: settings`}</Title>
      <Stack gap="s2">
        <Heading level={1} size="title1">
          {data.name}
        </Heading>
        <Text tone="secondary" mono>
          {data.slug}
        </Text>
      </Stack>

      {data.canManageSettings && (
        <Card variant="flat" title="Organization" description="Its name, and the address it is served on.">
          <Stack gap="s4">
            <RenameForm action={data.actions.rename} name={data.name} />
            <SlugForm action={data.actions.changeSlug} slug={data.slug} />
          </Stack>
        </Card>
      )}

      <Card variant="flat" title="Members" flush>
        <Table label="Members">
          <THead>
            <Tr>
              <Th>Name</Th>
              <Th>Email</Th>
              <Th>Role</Th>
              {data.canManageMembers && <Th>Change</Th>}
            </Tr>
          </THead>
          <TBody>
            {data.members.map((member) => (
              <Tr key={member.userId}>
                <Td>
                  <Stack direction="row" gap="s2" align="center">
                    <Text>{member.name}</Text>
                    {member.you && <Badge tone="outline">You</Badge>}
                  </Stack>
                </Td>
                <Td mono>{member.email}</Td>
                <Td>{label(member.role)}</Td>
                {data.canManageMembers && (
                  <Td>
                    <Stack direction="row" gap="s2" align="center">
                      <RoleForm
                        action={data.actions.changeRole}
                        member={member.userId}
                        role={member.role}
                        roles={data.roles}
                      />
                      <ActionButton
                        action={data.actions.removeMember}
                        field="member"
                        value={member.userId}
                        label={member.you ? 'Leave' : `Remove ${member.name}`}
                        busyLabel="Removing…"
                        variant="danger"
                      />
                    </Stack>
                  </Td>
                )}
              </Tr>
            ))}
          </TBody>
        </Table>
      </Card>

      {data.canManageMembers && (
        <Card
          variant="flat"
          title="Invite someone"
          description="They get an email with a link, good for a week."
        >
          <InviteForm action={data.actions.invite} roles={data.roles} />
        </Card>
      )}

      {data.invites.length > 0 && (
        <Card variant="flat" title="Open invites" flush>
          <Table label="Open invites">
            <THead>
              <Tr>
                <Th>Email</Th>
                <Th>Role</Th>
                <Th>Expires</Th>
                {data.canManageMembers && <Th>Revoke</Th>}
              </Tr>
            </THead>
            <TBody>
              {data.invites.map((invite) => (
                <Tr key={invite.id}>
                  <Td mono>{invite.email}</Td>
                  <Td>{label(invite.role)}</Td>
                  <Td muted>{new Date(invite.expiresAt).toUTCString()}</Td>
                  {data.canManageMembers && (
                    <Td>
                      <ActionButton
                        action={data.actions.revokeInvite}
                        field="invite"
                        value={invite.id}
                        label="Revoke"
                        busyLabel="Revoking…"
                        variant="ghost"
                      />
                    </Td>
                  )}
                </Tr>
              ))}
            </TBody>
          </Table>
        </Card>
      )}
    </Stack>
  );
}
