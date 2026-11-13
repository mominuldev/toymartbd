<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

/**
 * The subject of the finance and valuation report policies. Reports have no model of
 * their own — they read the ledger, order lines and stock — so the Gate needs a class to
 * key FinanceReportPolicy on (rule 21: every admin endpoint is policy-guarded).
 */
final class FinanceReport {}
