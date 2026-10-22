<?php

namespace App\Controllers;

use App\Models\DepartmentModel;
use App\Models\StaffProfileModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Backoffice;

/**
 * Self-service for the signed-in user. Every action works on auth()->id()
 * only; no user id is ever accepted from the request.
 */
class Profile extends BaseController
{
    /** Fields a user may ask an approver to change. */
    private const REQUESTABLE = ['job_title' => 'Job title', 'department_id' => 'Department', 'manager_id' => 'Manager'];

    public function index(): string
    {
        $id = (int) auth()->id();

        return view('profile/index', [
            'title'       => 'My profile',
            'active'      => 'profile',
            'me'          => $this->mine($id),
            'departments' => model(DepartmentModel::class)->orderBy('name')->findAll(),
            'managers'    => model(StaffProfileModel::class)->listing(['status' => 'active'])->where('sp.user_id !=', $id)->orderBy('sp.last_name')->get()->getResultArray(),
            'requestable' => self::REQUESTABLE,
            'requests'    => db_connect()->table('profile_change_requests')->where('user_id', $id)->orderBy('id', 'DESC')->limit(10)->get()->getResultArray(),
        ]);
    }

    public function update(): RedirectResponse
    {
        $id = (int) auth()->id();

        $rules = [
            'display_name' => 'permit_empty|max_length[160]',
            'phone'        => 'permit_empty|max_length[40]',
            'mobile'       => 'permit_empty|max_length[40]',
            'location'     => 'permit_empty|max_length[120]',
            'bio'          => 'permit_empty|max_length[2000]',
            'theme'        => 'required|in_list[auto,light,dark]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $profiles = model(StaffProfileModel::class);
        $before   = $profiles->find($id) ?? [];
        $post     = $this->request->getPost(array_keys($rules));
        $data     = array_map(static fn ($v) => ($v = trim((string) $v)) === '' ? null : $v, $post);
        $data['theme'] = $post['theme'];

        // An Entra-managed profile has its core identity fields owned by the directory.
        if (($before['source'] ?? 'local') === 'entra') {
            unset($data['display_name']);
        }

        $profiles->update($id, $data);
        service('audit')->log('profile.updated', 'user', $id, array_intersect_key($before, $data), $data);

        return redirect()->to('profile')->with('message', 'Profile saved.');
    }

    public function avatarUpload(): RedirectResponse
    {
        $id   = (int) auth()->id();
        $file = $this->request->getFile('avatar');

        if (! $this->validate(['avatar' => 'uploaded[avatar]|is_image[avatar]|mime_in[avatar,image/jpeg,image/png,image/webp]|max_size[avatar,4096]|max_dims[avatar,6000,6000]'])) {
            return redirect()->back()->with('errors', $this->validator->getErrors());
        }

        $dir = WRITEPATH . 'uploads/avatars';

        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            return redirect()->back()->with('error', 'Could not store the image.');
        }

        // Re-encode to a square 256px JPEG: strips EXIF/embedded payloads and normalises size.
        $name = bin2hex(random_bytes(12)) . '.jpg';

        try {
            service('image')->withFile($file->getRealPath())
                ->fit(256, 256, 'center')
                ->convert(IMAGETYPE_JPEG)
                ->save($dir . '/' . $name, 85);
        } catch (\Throwable $e) {
            log_message('error', 'Avatar processing failed: ' . $e->getMessage());

            return redirect()->back()->with('error', 'That image could not be processed. Try a different file.');
        }

        $profiles = model(StaffProfileModel::class);
        $old      = $profiles->find($id)['avatar_path'] ?? null;
        $profiles->update($id, ['avatar_path' => $name]);

        if ($old && is_file($dir . '/' . basename($old))) {
            unlink($dir . '/' . basename($old));
        }

        service('audit')->log('profile.avatar_changed', 'user', $id);

        return redirect()->to('profile')->with('message', 'Photo updated.');
    }

    public function avatarRemove(): RedirectResponse
    {
        $id  = (int) auth()->id();
        $old = model(StaffProfileModel::class)->find($id)['avatar_path'] ?? null;

        model(StaffProfileModel::class)->update($id, ['avatar_path' => null]);

        if ($old && is_file(WRITEPATH . 'uploads/avatars/' . basename($old))) {
            unlink(WRITEPATH . 'uploads/avatars/' . basename($old));
        }

        return redirect()->to('profile')->with('message', 'Photo removed.');
    }

    /**
     * Serves an avatar to any signed-in user (the directory is internal).
     */
    public function avatar(int $userId): ResponseInterface
    {
        $path = model(StaffProfileModel::class)->find($userId)['avatar_path'] ?? null;
        $file = $path ? WRITEPATH . 'uploads/avatars/' . basename($path) : null;

        if (! $file || ! is_file($file)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setHeader('Content-Type', 'image/jpeg')
            ->setHeader('Cache-Control', 'private, max-age=86400')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(file_get_contents($file));
    }

    public function requestChange(): RedirectResponse
    {
        $id    = (int) auth()->id();
        $field = (string) $this->request->getPost('field');

        if (! isset(self::REQUESTABLE[$field])) {
            return redirect()->back()->with('error', 'That field cannot be changed by request.');
        }

        if (! $this->validate(['reason' => 'permit_empty|max_length[500]', 'new_value' => 'required|max_length[255]'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $value = trim((string) $this->request->getPost('new_value'));

        if ($field !== 'job_title') {
            // department_id / manager_id must point at a real, different record.
            $table = $field === 'department_id' ? 'departments' : 'staff_profiles';
            $key   = $field === 'department_id' ? 'id' : 'user_id';

            if (! ctype_digit($value) || ! db_connect()->table($table)->where($key, (int) $value)->countAllResults() || ($field === 'manager_id' && (int) $value === $id)) {
                return redirect()->back()->withInput()->with('error', 'Choose a valid ' . strtolower(self::REQUESTABLE[$field]) . '.');
            }
        }

        $db = db_connect();

        if ($db->table('profile_change_requests')->where(['user_id' => $id, 'field' => $field, 'status' => 'pending'])->countAllResults()) {
            return redirect()->back()->with('error', 'You already have a pending request for that field.');
        }

        $db->table('profile_change_requests')->insert([
            'user_id' => $id, 'field' => $field, 'new_value' => $value,
            'reason'  => trim((string) $this->request->getPost('reason')) ?: null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        service('audit')->log('profile.change_requested', 'user', $id, null, ['field' => $field, 'new_value' => $value]);
        service('notifier')->sendToPermission(
            'users.edit',
            'profile.request_new',
            display_name() . ' requested a change to ' . strtolower(self::REQUESTABLE[$field]),
            null,
            'admin/requests',
            [$id],
        );

        return redirect()->to('profile')->with('message', 'Request sent for approval.');
    }

    public function cancelRequest(int $requestId): RedirectResponse
    {
        db_connect()->table('profile_change_requests')
            ->where(['id' => $requestId, 'user_id' => (int) auth()->id(), 'status' => 'pending'])->delete();

        return redirect()->to('profile')->with('message', 'Request cancelled.');
    }

    public function security(): string
    {
        $user = auth()->user();

        return view('profile/security', [
            'title'    => 'Password & security',
            'active'   => 'security',
            'isEntra'  => ($this->mine((int) $user->id)['source'] ?? 'local') === 'entra',
            'forced'   => $user->requiresPasswordReset(),
            'logins'   => db_connect()->table('auth_logins')->where('identifier', $user->email)->orderBy('id', 'DESC')->limit(10)->get()->getResultArray(),
            'activity' => db_connect()->table('audit_log')->where('actor_id', $user->id)->orderBy('id', 'DESC')->limit(10)->get()->getResultArray(),
        ]);
    }

    public function changePassword(): RedirectResponse
    {
        $user = auth()->user();

        if (($this->mine((int) $user->id)['source'] ?? 'local') === 'entra') {
            return redirect()->back()->with('error', 'Your password is managed in Microsoft Entra.');
        }

        $rules = [
            'current_password' => 'required',
            'password'         => 'required|min_length[' . config(\Config\Auth::class)->minimumPasswordLength . ']|max_byte[72]|strong_password[]',
            'password_confirm' => 'required|matches[password]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->with('errors', $this->validator->getErrors());
        }

        // Verifying the current password is a guessing oracle for a hijacked session: rate-limit it.
        if (! service('throttler')->check('pwchange_' . $user->id, 5, 300)) {
            return redirect()->back()->with('error', 'Too many attempts. Try again in a few minutes.');
        }

        $check = auth()->check(['email' => $user->email, 'password' => (string) $this->request->getPost('current_password')]);

        if (! $check->isOK()) {
            return redirect()->back()->with('error', 'Your current password is incorrect.');
        }

        if (hash_equals((string) $this->request->getPost('current_password'), (string) $this->request->getPost('password'))) {
            return redirect()->back()->with('error', 'Choose a password different from your current one.');
        }

        $users          = auth()->getProvider();
        $user->password = (string) $this->request->getPost('password');
        $users->save($user);
        $user->undoForcePasswordReset();
        service('mfa')->revokeDevices((int) $user->id);

        service('audit')->log('profile.password_changed', 'user', $user->id);
        service('notifier')->send((int) $user->id, 'account.password_changed', 'Your password was changed', 'If this was not you, contact an administrator immediately.');

        return redirect()->to('profile/security')->with('message', 'Password updated.');
    }

    public function notificationSettings(): string
    {
        $id    = (int) auth()->id();
        $types = config(Backoffice::class)->notificationTypes;
        $prefs = [];

        foreach ($types as $type => $def) {
            $prefs[$type] = service('notifier')->preference($id, $type);
        }

        return view('profile/notifications', ['title' => 'Notification settings', 'active' => 'notifications', 'types' => $types, 'prefs' => $prefs]);
    }

    public function saveNotificationSettings(): RedirectResponse
    {
        service('notifier')->savePreferences((int) auth()->id(), (array) $this->request->getPost('pref'));

        return redirect()->to('profile/notifications')->with('message', 'Preferences saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function mine(int $id): array
    {
        return model(StaffProfileModel::class)->listing()->where('sp.user_id', $id)->get()->getRowArray()
            ?? throw PageNotFoundException::forPageNotFound();
    }
}
