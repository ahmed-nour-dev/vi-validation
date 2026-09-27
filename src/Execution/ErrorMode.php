<?php

declare(strict_types=1);

namespace Vi\Validation\Execution;

/**
 * How much error detail is collected per row.
 *
 * Validity never depends on the mode: a row that fails under All fails under every mode.
 * Only how many rules are evaluated after the first failure, and how much error data is
 * retained, changes.
 *
 * - All:           every failing rule of every field (bounded by max_errors). Default; what
 *                  APIs need to show a full error bag.
 * - FirstPerField: at most one error per field, the first rule that failed (as if every
 *                  field had `bail`). Good for form-style feedback and imports that report
 *                  one problem per column.
 * - FirstPerRow:   stop the row at its first error (same as fail_fast). Cheapest way to
 *                  learn *that* a row is invalid plus one reason.
 * - CountOnly:     keep no error details, only how many errors each field had. errors() and
 *                  messages() are empty; isValid(), errorCount(), errorCountsByField() and
 *                  failedFields() work. For aggregate statistics over millions of rows.
 */
enum ErrorMode: string
{
    case All = 'all';
    case FirstPerField = 'first_per_field';
    case FirstPerRow = 'first_per_row';
    case CountOnly = 'count_only';
}
