<!doctype html>
<html>
<body style="font-family: Arial, sans-serif; color: #1f2937; background: #f4f6fb; padding: 24px;">
<table role="presentation" width="100%" style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 24px;">
    <tr><td>
        <h2 style="margin: 0 0 12px; font-size: 18px;"><?= esc($title) ?></h2>
        <?php if ($body): ?><p style="line-height: 1.5;"><?= nl2br(esc($body)) ?></p><?php endif; ?>
        <?php if ($url): ?>
            <p><a href="<?= esc($url, 'attr') ?>" style="display: inline-block; background: #4f46e5; color: #ffffff; text-decoration: none; padding: 10px 18px; border-radius: 6px;">Open in Back Office</a></p>
        <?php endif; ?>
        <p style="color: #6b7280; font-size: 12px; margin-top: 24px;">You can change which emails you receive under Profile &rarr; Notifications.</p>
    </td></tr>
</table>
</body>
</html>
