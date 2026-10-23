<!doctype html>
<html>
<body style="font-family: Arial, Helvetica, sans-serif; color:#212529; line-height:1.5;">
    <p>Hello <?= esc($name) ?>,</p>
    <p>An account has been created for you on the Maloney Group invoice approval system.</p>
    <p>
        <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>"
           style="display:inline-block;padding:10px 18px;background:#0d6efd;color:#fff;text-decoration:none;border-radius:6px;">
            Set your password and sign in
        </a>
    </p>
    <p style="color:#6c757d;font-size:13px;">
        The link works once and expires in <?= esc((string) $expires) ?> days. If it has expired,
        use "Email me a sign-in link" on the login page or ask an administrator to resend it.
    </p>
</body>
</html>
