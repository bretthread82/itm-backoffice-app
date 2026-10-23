<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\ApiController;
use App\Services\ApiPresenter;
use App\Services\InvoiceDocuments;
use App\Services\InvoiceFiles;
use App\Services\InvoiceQuery;
use App\Services\InvoiceTimeline;
use App\Services\ReportService;
use App\Services\WorkflowEngine;
use CodeIgniter\HTTP\ResponseInterface;

class Invoices extends ApiController
{
    private const STATUSES = ['open', 'outstanding', 'draft', 'in_approval', 'approved', 'returned', 'rejected', 'cancelled', 'processed', 'paid', 'completed'];

    public function index(): ResponseInterface
    {
        $get = $this->request->getGet();

        if (($problem = $this->badFilter($get)) !== null) {
            return $this->error(422, 'invalid_filter', $problem);
        }
        $company = $this->companyParam();
        if ($company === false) {
            return $this->error(422, 'invalid_filter', 'company is not a company you can see.');
        }

        [$builder]       = (new InvoiceQuery())->build($this->uid(), $get, $company);
        [$page, $perPage] = $this->paging();

        $total = (clone $builder)->countAllResults(false);
        $rows  = $builder->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return $this->ok(['data' => array_map(ApiPresenter::invoice(...), $rows), 'meta' => $this->meta($page, $perPage, $total)]);
    }

    public function show(int $id): ResponseInterface
    {
        $inv = (new InvoiceQuery())->find($this->uid(), $id);
        if ($inv === null) {
            return $this->notFound('Invoice not found.');
        }

        $engine  = new WorkflowEngine();
        $files   = new InvoiceFiles();
        $docs    = new InvoiceDocuments();
        $file    = $files->current($id);
        $current = $engine->currentStep($inv);

        return $this->ok(['data' => ApiPresenter::invoiceDetail(
            $inv,
            $file,
            $file !== null && $files->path($file) !== null,
            $engine->stepsOf($inv),
            $current,
            $current !== null ? $engine->label($current) : '',
            array_map(static fn (array $d): array => $d + ['here' => $docs->path($d) !== null], $docs->forInvoice($id)),
        )]);
    }

    public function document(int $id, int $docId): ResponseInterface
    {
        if ((new InvoiceQuery())->find($this->uid(), $id) === null) {
            return $this->notFound('Invoice not found.');
        }

        $docs = new InvoiceDocuments();
        $doc  = $docs->find($id, $docId);
        $path = $doc !== null ? $docs->path($doc) : null;
        if ($doc === null || $path === null) {
            return $this->notFound('The document is no longer stored on this server.');
        }

        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string) $doc['original_name']) ?: 'document';

        return $this->response
            ->setHeader('Content-Type', (string) $doc['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('Content-Length', (string) filesize($path))
            ->setBody((string) file_get_contents($path));
    }

    public function timeline(int $id): ResponseInterface
    {
        if ((new InvoiceQuery())->find($this->uid(), $id) === null) {
            return $this->notFound('Invoice not found.');
        }
        [$events] = (new InvoiceTimeline())->for($id);

        return $this->ok(['data' => array_map(ApiPresenter::timelineEvent(...), $events)]);
    }

    public function file(int $id): ResponseInterface
    {
        if ((new InvoiceQuery())->find($this->uid(), $id) === null) {
            return $this->notFound('Invoice not found.');
        }

        $files = new InvoiceFiles();
        $file  = $files->current($id);
        $path  = $file !== null ? $files->path($file) : null;
        if ($path === null) {
            return $this->notFound('The invoice file is no longer stored on this server.');
        }

        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string) $file['original_name']) ?: 'invoice.pdf';

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('Content-Length', (string) filesize($path))
            ->setBody((string) file_get_contents($path));
    }

    /**
     * @param array<string, mixed> $get
     *
     * @return string|null what is wrong with the filters, or null when they are fine
     */
    private function badFilter(array $get): ?string
    {
        $status = (string) ($get['status'] ?? '');
        if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
            return 'status must be one of: ' . implode(', ', self::STATUSES) . '.';
        }
        $age = (string) ($get['age'] ?? '');
        if ($age !== '' && ! in_array($age, ReportService::AGE_FILTERS, true)) {
            return 'age must be one of: ' . implode(', ', ReportService::AGE_FILTERS) . '.';
        }
        foreach (['date_from', 'date_to'] as $key) {
            $v = (string) ($get[$key] ?? '');
            if ($v !== '' && (($d = date_create_from_format('Y-m-d', $v)) === false || $d->format('Y-m-d') !== $v)) {
                return $key . ' must be a date like 2026-09-30.';
            }
        }
        foreach (['min', 'max'] as $key) {
            $v = (string) ($get[$key] ?? '');
            if ($v !== '' && ! is_numeric($v)) {
                return $key . ' must be a number.';
            }
        }
        $sort = (string) ($get['sort'] ?? '');
        if ($sort !== '' && ! array_key_exists($sort, InvoiceQuery::SORTS)) {
            return 'sort must be one of: ' . implode(', ', array_keys(InvoiceQuery::SORTS)) . '.';
        }
        $dir = strtolower((string) ($get['dir'] ?? ''));
        if ($dir !== '' && ! in_array($dir, ['asc', 'desc'], true)) {
            return 'dir must be asc or desc.';
        }

        return null;
    }
}
