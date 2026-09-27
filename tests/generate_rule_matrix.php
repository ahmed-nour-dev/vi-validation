<?php

/**
 * Regenerates docs/rules.md from resources/compatibility-matrix.json.
 *
 *   php tests/generate_rule_matrix.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Vi\Validation\Tests\Support\RuleMatrixDocument;

file_put_contents(RuleMatrixDocument::path(), RuleMatrixDocument::render());
echo 'Wrote ' . RuleMatrixDocument::path() . PHP_EOL;
