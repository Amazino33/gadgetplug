<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Invitation - GadgetPlug</title>
</head>
<body style="font-family: sans-serif; background: #f9fafb; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0;">

    <div style="background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); width: 100%; max-width: 400px;">
        <h1 style="font-size: 22px; font-weight: 700; color: #111827; margin-bottom: 8px;">Accept Invitation</h1>
        <p style="color: #6b7280; font-size: 14px; margin-bottom: 24px;">
            You've been invited to join a vendor team on GadgetPlug.
        </p>

        <form method="POST" action="{{ route('vendor.invite.store', $token) }}">
            @csrf

            {{-- Add this at the top of the form div --}}
            @if(session('error'))
                <div style="margin-bottom: 16px; padding: 12px; background: #fee2e2; border: 1px solid #fca5a5; color: #dc2626; font-size: 14px; border-radius: 8px;">
                    {{ session('error') }}
                </div>
            @endif

            @if($errors->any())
                <div style="margin-bottom: 16px; padding: 12px; background: #fee2e2; border: 1px solid #fca5a5; color: #dc2626; font-size: 14px; border-radius: 8px;">
                    @foreach($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px;">Email</label>
                <input type="email" value="{{ $email }}" disabled
                    style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; background: #f3f4f6; color: #6b7280; box-sizing: border-box;">
            </div>

            @if(!$userExists)
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px;">Your Name</label>
                    <input type="text" name="name" required
                        style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; box-sizing: border-box;">
                    @error('name') <span style="color: #dc2626; font-size: 12px;">{{ $message }}</span> @enderror
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px;">Password</label>
                    <input type="password" name="password" required
                        style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; box-sizing: border-box;">
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px;">Confirm Password</label>
                    <input type="password" name="password_confirmation" required
                        style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; box-sizing: border-box;">
                </div>
            @endif

            <button type="submit"
                style="width: 100%; background: #2563eb; color: white; padding: 12px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer;">
                Accept & Join Team
            </button>
        </form>
    </div>

</body>
</html>