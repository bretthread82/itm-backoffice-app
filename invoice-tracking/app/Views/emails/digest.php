<!doctype html>
<html>
<body style="font-family: Arial, Helvetica, sans-serif; color:#212529; line-height:1.5;">
    <h2 style="font-size:18px;margin:0 0 12px;"><?= esc($title) ?></h2>
    <?php foreach ($sections as $section): ?>
        <h3 style="font-size:15px;margin:18px 0 6px;"><?= esc($section['heading']) ?></h3>
        <ul style="padding-left:18px;margin:0;">
            <?php foreach ($section['items'] as $item): ?>
                <li style="margin-bottom:4px;"><a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"><?= esc($item['text']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
    <p style="margin-top:20px;">
        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" style="display:inline-block;padding:10px 18px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;">Open my approvals</a>
    </p>
    <p style="color:#6c757d;font-size:12px;">You get this summary only when something needs your attention. Change it under Account &gt; Notifications.</p>
</body>
</html>
