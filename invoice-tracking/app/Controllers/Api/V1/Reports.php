<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\ApiController;
use App\Services\ApiPresenter;
use App\Services\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

class Reports extends ApiController
{
    /**
     * Outstanding invoices with days past due, plus the ageing summary. ?company= and ?group=
     * (a sub-group id, including the groups beneath it) narrow it; access rules still apply.
     */
    public function outstanding(): ResponseInterface
    {
        $company = $this->companyParam();
        if ($company === false) {
            return $this->error(422, 'invalid_filter', 'company is not a company you can see.');
        }
        $group = $this->request->getGet('group');
        if ($group !== null && $group !== '' && ! ctype_digit((string) $group)) {
            return $this->error(422, 'invalid_filter', 'group must be a sub-group id.');
        }

        $filters = array_filter(['company' => $company, 'group' => (int) $group]);
        $reports = new ReportService();
        $rows    = $reports->outstanding($this->uid(), $filters);

        return $this->ok(['data' => [
            'as_of'     => date('Y-m-d'),
            'summary'   => ApiPresenter::aggregate(ReportService::aggregate($rows)),
            'invoices'  => array_map(ApiPresenter::outstandingRow(...), $rows),
        ]]);
    }
}
