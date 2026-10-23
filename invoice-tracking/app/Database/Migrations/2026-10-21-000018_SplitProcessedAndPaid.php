<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * "Completed" becomes two separate stages: processed (the process step is done) and paid (the new pay step is
 * done, which is where the payment reference and date are recorded).
 *
 *  - invoices get processed_at / paid_at;
 *  - completed invoices with a payment date become paid, the others processed;
 *  - every route that ends in a process step gets a pay step after it, for the same assignee, so the people who
 *    record payments today keep doing so; invoices still moving along such a route get the same step added to
 *    their snapshot (otherwise they could be processed but never paid). This is the one place a snapshot is edited;
 *  - the "my invoice was fully approved" email preference is split into processed and paid.
 *
 * Safe to run again: it only touches rows that are not already converted. MySQL DDL is not transactional.
 */
class SplitProcessedAndPaid extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('invoices', [
            'processed_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'completed_at'],
            'paid_at'      => ['type' => 'DATETIME', 'null' => true, 'after' => 'processed_at'],
        ]);

        $this->convertStatuses();
        $this->addPayStepsToRoutes();
        $this->addPayStepsToSnapshots();
        $this->splitNotificationPreference();
    }

    public function down(): void
    {
        $this->db->query("UPDATE invoices SET status = 'completed' WHERE status IN ('processed', 'paid') AND completed_at IS NOT NULL");
        // Invoices that were waiting at their pay step go back to the process step's state.
        $this->db->query("UPDATE invoices SET status = 'approved' WHERE status = 'processed'");

        $this->db->query("DELETE FROM route_steps WHERE step_type = 'pay'");
        $this->forge->dropColumn('invoices', ['processed_at', 'paid_at']);

        $this->db->query("DELETE FROM notification_prefs WHERE event IN ('invoice.processed', 'invoice.paid')");
    }

    private function convertStatuses(): void
    {
        $this->db->query("UPDATE invoices SET status = 'paid', processed_at = completed_at, paid_at = completed_at
                          WHERE status = 'completed' AND payment_date IS NOT NULL");
        $this->db->query("UPDATE invoices SET status = 'processed', processed_at = completed_at
                          WHERE status = 'completed'");
    }

    private function addPayStepsToRoutes(): void
    {
        foreach ($this->db->table('routes')->select('id')->get()->getResultArray() as $r) {
            $steps = $this->db->table('route_steps')->where('route_id', (int) $r['id'])->orderBy('step_order')->get()->getResultArray();
            $last  = null;
            $max   = 0;
            foreach ($steps as $s) {
                $max = max($max, (int) $s['step_order']);
                if (in_array($s['step_type'], ['approve', 'process', 'pay'], true)) {
                    $last = $s;
                }
            }
            if ($last === null || $last['step_type'] !== 'process') {
                continue;       // no steps, ends in an approval, or already has a pay step
            }

            unset($last['id']);
            $this->db->table('route_steps')->insert(['step_type' => 'pay', 'step_order' => $max + 1] + $last);
        }
    }

    private function addPayStepsToSnapshots(): void
    {
        $rows = $this->db->table('invoices')->select('id, route_snapshot')->whereIn('status', ['in_approval', 'approved'])
            ->where('route_snapshot IS NOT NULL')->get()->getResultArray();

        foreach ($rows as $row) {
            $snap = json_decode((string) $row['route_snapshot'], true);
            if (! is_array($snap) || ! is_array($snap['steps'] ?? null)) {
                continue;
            }

            $last = null;
            $max  = 0;
            foreach ($snap['steps'] as $s) {
                $max = max($max, (int) $s['order']);
                if (in_array($s['step_type'], ['approve', 'process', 'pay'], true)) {
                    $last = $s;
                }
            }
            if ($last === null || $last['step_type'] !== 'process') {
                continue;
            }

            $snap['steps'][] = ['order' => $max + 1, 'step_type' => 'pay'] + $last;
            $this->db->table('invoices')->where('id', (int) $row['id'])->update(['route_snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function splitNotificationPreference(): void
    {
        foreach ($this->db->table('notification_prefs')->where('event', 'invoice.completed')->get()->getResultArray() as $p) {
            unset($p['id']);
            foreach (['invoice.processed', 'invoice.paid'] as $event) {
                if ($this->db->table('notification_prefs')->where(['user_id' => $p['user_id'], 'event' => $event])->countAllResults() === 0) {
                    $this->db->table('notification_prefs')->insert(['event' => $event] + $p);
                }
            }
        }
        $this->db->table('notification_prefs')->where('event', 'invoice.completed')->delete();
    }
}
