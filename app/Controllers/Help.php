<?php

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * User manual and FAQ. Open to every signed-in user; HelpService shows each person only the
 * topics their permissions allow, and a topic they may not see is a 404.
 */
class Help extends BaseController
{
    public function index(): string
    {
        $uid   = (int) auth()->id();
        $help  = service('help');
        $query = trim((string) $this->request->getGet('q'));

        return view('help/index', [
            'title'    => 'Help',
            'subtitle' => 'User manual and frequently asked questions for the areas you can use.',
            'topics'   => $help->topics($uid),
            'roles'    => $help->roleNames($uid),
            'q'        => $query,
            'hits'     => $help->search($uid, $query),
        ]);
    }

    public function topic(string $slug): string
    {
        $topic = service('help')->topic((int) auth()->id(), $slug);

        if ($topic === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('help/topic', ['title' => $topic['title'], 'subtitle' => $topic['summary'], 'topic' => $topic]);
    }

    public function faq(): string
    {
        $query = trim((string) $this->request->getGet('q'));

        return view('help/faq', [
            'title'    => 'Frequently asked questions',
            'subtitle' => 'Quick answers, grouped by area.',
            'groups'   => service('help')->faqGroups((int) auth()->id(), $query),
            'q'        => $query,
        ]);
    }
}
