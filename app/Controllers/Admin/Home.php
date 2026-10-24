<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * The Admin dashboard, reached from the account menu: every administration option the user may use.
 * What is listed comes from the module registry (admin_tree()), already filtered by permission.
 */
class Home extends BaseController
{
    public function index(): string
    {
        $sections = admin_tree();

        if ($sections === []) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('admin/home', [
            'title'    => 'Administration',
            'subtitle' => 'Everything you can set up and manage',
            'sections' => $sections,
        ]);
    }
}
