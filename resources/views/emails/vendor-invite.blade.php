<x-mail::message>
# You've Been Invited

You have been invited to join **{{ $vendorName }}** as a team member on GadgetPlug.

Click the button below to accept the invitation and set up your account.

<x-mail::button :url="$inviteUrl">
Accept Invitation
</x-mail::button>

This invite link expires in **7 days**.

If you did not expect this invitation, you can safely ignore this email.

Thanks,
{{ config('app.name') }}
</x-mail::message>