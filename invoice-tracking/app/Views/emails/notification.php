<!doctype html>
<html>
<body style="font-family: Arial, Helvetica, sans-serif; color:#212529; line-height:1.5;">
    <h2 style="font-size:18px;margin:0 0 12px;"><?= esc($title) ?></h2>
    <?php if (trim((string) $body) !== ''): ?>
        <p><?= nl2br(esc($body)) ?></p>
    <?php endif; ?>
    <p>
        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" style="display:inline-block;padding:10px 18px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;">Open the invoice</a>
    </p>
    <p style="color:#6c757d;font-size:12px;">Maloney Group invoice approval. You can change which emails you receive under Account &gt; Notifications.</p>
</body>
</html>
