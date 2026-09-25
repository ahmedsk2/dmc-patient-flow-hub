@if($hasAccount)
Someone tried to create a new DMC Patient-Flow Hub account using this email address, which already
has an account.

If this was you: you don't need to register again — use Forgot username ({{ url('/forgot-username') }})
or Forgot password ({{ url('/forgot-password') }}) instead.

If this was not you, you can safely ignore this email — no account was created and nothing on your
account has changed.
@else
Someone tried to create a new DMC Patient-Flow Hub account using this email address, and another
registration is already in progress for it.

If this was you, continue the registration you already started on the other device or browser
rather than starting again. If this was not you, you can safely ignore this email — no account has
been created yet.
@endif

— DMC Internal Medicine
