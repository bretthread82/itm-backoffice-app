<?php

declare(strict_types=1);

namespace App\Controllers\Receivables\Admin;

use App\Controllers\BaseController;
use App\Core\Receivables\ArAudit;
use App\Core\Receivables\ArRouteVariants;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Route variants: the named kinds of approval route (Standard plus extras such as "High Value").
 * The key is fixed when a variant is created because routes and invoice snapshots refer to it;
 * the display name, order and on/off state can change at any time.
 */
class RouteVariants extends BaseController
{
    public function index(): string
    {
        $variants = db_connect()->query(
            'SELECT v.*, (SELECT COUNT(*) FROM ar_routes r WHERE r.variant = v.variant_key) AS route_count
             FROM ar_route_variants v ORDER BY v.is_standard DESC, v.sort_order, v.name',
        )->getResultArray();

        return view('receivables/admin/route_variants/index', ['title' => 'Route variants', 'variants' => $variants]);
    }

    public function new(): string
    {
        return view('receivables/admin/route_variants/new', ['title' => 'New route variant']);
    }

    public function create(): RedirectResponse
    {
        if (! $this->validate(['name' => 'required|max_length[100]', 'sort_order' => 'permit_empty|is_natural'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $db   = db_connect();
        $name = trim((string) preg_replace('/\s+/', ' ', (string) $this->request->getPost('name')));
        $key  = $this->slug((string) ($this->request->getPost('variant_key') ?: $name));

        if ($key === '' || $key === ArRouteVariants::STANDARD_KEY) {
            return redirect()->back()->withInput()->with('error', 'Choose a different name; that one cannot be used as an internal key.');
        }
        if ($db->table('ar_route_variants')->where('variant_key', $key)->countAllResults() > 0) {
            return redirect()->back()->withInput()->with('error', 'A variant with that key already exists. Change the key.');
        }
        if ($db->table('ar_route_variants')->where('LOWER(name)', strtolower($name))->countAllResults() > 0) {
            return redirect()->back()->withInput()->with('error', 'A variant with that name already exists.');
        }

        $sort = (string) $this->request->getPost('sort_order');
        $db->table('ar_route_variants')->insert([
            'variant_key' => $key, 'name' => $name, 'is_standard' => 0, 'active' => 1,
            'sort_order'  => $sort === '' ? 100 : (int) $sort, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        (new ArAudit())->log('ar.route_variant_created', (int) auth()->id(), 'ar_route_variant', (int) $db->insertID(), ['key' => $key, 'name' => $name]);

        return redirect()->to('receivables-admin/route-variants')->with('message', 'Variant added. Create a route for it, then set when it applies.');
    }

    public function edit(int $id): string
    {
        return view('receivables/admin/route_variants/edit', ['title' => 'Edit route variant', 'variant' => $this->variant($id)]);
    }

    public function update(int $id): RedirectResponse
    {
        $variant = $this->variant($id);

        if (! $this->validate(['name' => 'required|max_length[100]', 'sort_order' => 'permit_empty|is_natural'])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $db   = db_connect();
        $name = trim((string) preg_replace('/\s+/', ' ', (string) $this->request->getPost('name')));
        if ($db->table('ar_route_variants')->where('LOWER(name)', strtolower($name))->where('id !=', $id)->countAllResults() > 0) {
            return redirect()->back()->withInput()->with('error', 'A variant with that name already exists.');
        }

        $isStandard = (int) $variant['is_standard'] === 1;
        $sort       = (string) $this->request->getPost('sort_order');
        $db->table('ar_route_variants')->where('id', $id)->update([
            'name'       => $name,
            'active'     => $isStandard || $this->request->getPost('active') ? 1 : 0,   // standard can never be switched off
            'sort_order' => $isStandard ? 0 : ($sort === '' ? (int) $variant['sort_order'] : (int) $sort),
        ]);
        (new ArAudit())->log('ar.route_variant_updated', (int) auth()->id(), 'ar_route_variant', $id, ['name' => $name]);

        return redirect()->to('receivables-admin/route-variants')->with('message', 'Variant saved.');
    }

    public function delete(int $id): RedirectResponse
    {
        $variant = $this->variant($id);
        $db      = db_connect();

        if ((int) $variant['is_standard'] === 1) {
            return redirect()->back()->with('error', 'The standard variant cannot be deleted.');
        }
        if ($db->table('ar_routes')->where('variant', $variant['variant_key'])->countAllResults() > 0) {
            return redirect()->back()->with('error', 'Routes use this variant. Switch it off instead of deleting it.');
        }

        $db->table('ar_route_variants')->where('id', $id)->delete();
        (new ArAudit())->log('ar.route_variant_deleted', (int) auth()->id(), 'ar_route_variant', $id, ['key' => $variant['variant_key']]);

        return redirect()->to('receivables-admin/route-variants')->with('message', 'Variant deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function variant(int $id): array
    {
        return (new ArRouteVariants())->find($id) ?? throw PageNotFoundException::forPageNotFound();
    }

    /** Lower-case a-z, 0-9 and underscores, at most 20 characters (the ar_routes.variant column width). */
    private function slug(string $text): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($text)), '_');

        return rtrim(substr($slug, 0, 20), '_');
    }
}
