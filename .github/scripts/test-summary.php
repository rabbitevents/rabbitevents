<?php

$phpVersion = $argv[1] ?? PHP_VERSION;
$summary = "## 🧪 PHPUnit Test & Coverage Report (PHP {$phpVersion})\n\n";

$junitFile = getcwd() . '/junit.xml';
if (file_exists($junitFile)) {
    $junit = simplexml_load_file($junitFile);
    $totalTests = 0;
    $failures = 0;
    $errors = 0;
    $skipped = 0;
    $assertions = 0;
    $time = 0.0;
    $failedCases = [];

    // Traverse all testsuites
    $suites = $junit->xpath('//testsuite');
    foreach ($suites as $suite) {
        if (isset($suite->testcase)) {
            foreach ($suite->testcase as $case) {
                $totalTests++;
                $assertions += (int) ($case['assertions'] ?? 0);
                $time += (float) ($case['time'] ?? 0);

                if (isset($case->failure)) {
                    $failures++;
                    $failedCases[] = [
                        'name' => (string) ($case['name'] ?? 'Unknown test'),
                        'class' => (string) ($case['class'] ?? ($case['classname'] ?? '')),
                        'message' => trim((string) $case->failure),
                    ];
                } elseif (isset($case->error)) {
                    $errors++;
                    $failedCases[] = [
                        'name' => (string) ($case['name'] ?? 'Unknown test'),
                        'class' => (string) ($case['class'] ?? ($case['classname'] ?? '')),
                        'message' => trim((string) $case->error),
                    ];
                } elseif (isset($case->skipped)) {
                    $skipped++;
                }
            }
        }
    }

    $passed = $totalTests - $failures - $errors - $skipped;
    $statusIcon = ($failures === 0 && $errors === 0) ? '✅' : '❌';
    $timeFormatted = round($time, 3) . 's';

    $summary .= "### {$statusIcon} Test Results\n\n";
    $summary .= "| Status | Total | Passed | Failed | Errors | Skipped | Assertions | Duration |\n";
    $summary .= "|:------:|:-----:|:------:|:------:|:------:|:-------:|:----------:|:--------:|\n";
    $summary .= "| {$statusIcon} | **{$totalTests}** | {$passed} | {$failures} | {$errors} | {$skipped} | {$assertions} | {$timeFormatted} |\n\n";

    if (!empty($failedCases)) {
        $summary .= "<details><summary>❌ <b>Failed Tests (" . count($failedCases) . ")</b></summary>\n\n";
        foreach ($failedCases as $failed) {
            $summary .= "#### `{$failed['class']}::{$failed['name']}`\n";
            $summary .= "```\n" . substr($failed['message'], 0, 1000) . "\n```\n\n";
        }
        $summary .= "</details>\n\n";
    }
}

$coverageFile = getcwd() . '/coverage.xml';
if (file_exists($coverageFile)) {
    $clover = simplexml_load_file($coverageFile);
    if (isset($clover->project->metrics)) {
        $m = $clover->project->metrics;
        $statements = (int) ($m['statements'] ?? 0);
        $coveredStatements = (int) ($m['coveredstatements'] ?? 0);
        $methods = (int) ($m['methods'] ?? 0);
        $coveredMethods = (int) ($m['coveredmethods'] ?? 0);
        $elements = (int) ($m['elements'] ?? 0);
        $coveredElements = (int) ($m['coveredelements'] ?? 0);

        $linePct = $statements > 0 ? round(($coveredStatements / $statements) * 100, 1) : 0;
        $methodPct = $methods > 0 ? round(($coveredMethods / $methods) * 100, 1) : 0;
        $elemPct = $elements > 0 ? round(($coveredElements / $elements) * 100, 1) : 0;

        $badge = function (float $pct): string {
            if ($pct >= 90) return "🟢 **{$pct}%**";
            if ($pct >= 80) return "🟢 **{$pct}%**";
            if ($pct >= 60) return "🟡 **{$pct}%**";
            return "🔴 **{$pct}%**";
        };

        $summary .= "### 📊 Code Coverage\n\n";
        $summary .= "| Metric | Covered | Total | Coverage |\n";
        $summary .= "|:-------|--------:|------:|:---------|\n";
        $summary .= "| **Lines / Statements** | {$coveredStatements} | {$statements} | " . $badge($linePct) . " |\n";
        $summary .= "| **Methods / Functions** | {$coveredMethods} | {$methods} | " . $badge($methodPct) . " |\n";
        $summary .= "| **Total Elements** | {$coveredElements} | {$elements} | " . $badge($elemPct) . " |\n\n";

        if (in_array('--badge', $argv ?? [], true)) {
            generateBadgeSvg($linePct, getcwd() . '/.github/badges/coverage.svg');
        }
    }
}

function generateBadgeSvg(float $pct, string $outputPath): void {
    if ($pct >= 90) {
        $color = '#4c1'; // bright green
    } elseif ($pct >= 80) {
        $color = '#97ca00'; // green
    } elseif ($pct >= 70) {
        $color = '#a4a61d'; // yellowgreen
    } elseif ($pct >= 60) {
        $color = '#dfb317'; // yellow
    } else {
        $color = '#e05d44'; // red
    }

    $val = $pct . '%';
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="104" height="20">
  <linearGradient id="b" x2="0" y2="100%">
    <stop offset="0" stop-color="#bbb" stop-opacity=".1"/>
    <stop offset="1" stop-opacity=".1"/>
  </linearGradient>
  <mask id="a">
    <rect width="104" height="20" rx="3" fill="#fff"/>
  </mask>
  <g mask="url(#a)">
    <path fill="#555" d="M0 0h61v20H0z"/>
    <path fill="{$color}" d="M61 0h43v20H61z"/>
    <path fill="url(#b)" d="M0 0h104v20H0z"/>
  </g>
  <g fill="#fff" text-anchor="middle" font-family="DejaVu Sans,Verdana,Geneva,sans-serif" font-size="11">
    <text x="30.5" y="15" fill="#010101" fill-opacity=".3">coverage</text>
    <text x="30.5" y="14">coverage</text>
    <text x="82.5" y="15" fill="#010101" fill-opacity=".3">{$val}</text>
    <text x="82.5" y="14">{$val}</text>
  </g>
</svg>
SVG;

    @mkdir(dirname($outputPath), 0755, true);
    file_put_contents($outputPath, $svg);
}

$summaryFile = getenv('GITHUB_STEP_SUMMARY');
if ($summaryFile && is_writable(dirname($summaryFile))) {
    file_put_contents($summaryFile, $summary, FILE_APPEND);
} else {
    echo $summary;
}
