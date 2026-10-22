<!doctype html>
<html>
<body style="font-family: Arial, sans-serif; color: #1f2937; background: #f4f6fb; padding: 24px;">
<table role="presentation" width="100%" style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 24px;">
    <tr><td>
        <h2 style="margin: 0 0 12px; font-size: 18px;">Your sign-in code</h2>
        <p style="line-height: 1.5;">Enter this code to finish signing in to Back Office:</p>
        <p style="font-size: 28px; letter-spacing: 6px; font-weight: bold; margin: 16px 0;"><?= esc($code) ?></p>
        <p style="color: #6b7280; font-size: 12px; line-height: 1.5;">It works once and expires in <?= (int) $minutes ?> minutes. If you did not just try to sign in, someone may know your password. Change it and tell an administrator. Never share this code.</p>
    </td></tr>
</table>
</body>
</html>
