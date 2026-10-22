<!doctype html>
<html>
<body style="font-family: Arial, sans-serif; color: #1f2937; background: #f4f6fb; padding: 24px;">
<table role="presentation" width="100%" style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 24px;">
    <tr><td>
        <h2 style="margin: 0 0 12px; font-size: 18px;">Reset your password</h2>
        <p style="line-height: 1.5;">Hi <?= esc($name) ?>,</p>
        <p style="line-height: 1.5;">We received a request to reset the password for your Back Office account. Use the button below to choose a new one. The link works once and expires in <?= (int) $minutes ?> minutes.</p>
        <p><a href="<?= esc($url, 'attr') ?>" style="display: inline-block; background: #4f46e5; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 6px;">Choose a new password</a></p>
        <p style="color: #6b7280; font-size: 12px; line-height: 1.5;">If the button does not work, copy this address into your browser:<br><?= esc($url) ?></p>
        <p style="color: #6b7280; font-size: 12px; line-height: 1.5;">If you did not ask for this, you can ignore this email. Your password will not change.</p>
    </td></tr>
</table>
</body>
</html>
