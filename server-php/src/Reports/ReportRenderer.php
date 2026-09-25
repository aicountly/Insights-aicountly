<?php

declare(strict_types=1);

namespace Aicountly\Api\Reports;

/**
 * A report, laid out as a PDF.
 *
 * WHAT GOES ON THE PAGE, and none of it is decoration: the title, the company,
 * the branch, the financial year, the period and its comparison, the filters,
 * when it was generated and by whom, how fresh each source was, and — where it
 * applies — a warning in plain sight that the figures cover part of the data
 * only. A printed figure outlives the screen it came from, so everything a
 * reader would need to judge it travels with it.
 *
 * The renderer draws with PdfDocument, which uses the base-fourteen fonts. That
 * means Latin script only: a name in Devanagari cannot be drawn, and the
 * renderer says so on the page in place of the name rather than dropping the
 * row. A report that silently omits a customer is worse than one that admits
 * it could not print their name.
 */
final class ReportRenderer
{
    private const MARGIN = 40.0;

    /** @param array<string, mixed> $report */
    public function render(array $report): string
    {
        $pdf = new PdfDocument();
        $right = PdfDocument::A4_WIDTH - self::MARGIN;

        $this->heading($pdf, $report, $right);
        $this->provenance($pdf, $report, $right);

        if (($report['coverage'] ?? 'complete') === 'partial') {
            $this->banner($pdf, 'Partial data — read the notes at the end before quoting these figures.', $right);
        }

        $this->table($pdf, $report, $right);
        $this->notes($pdf, $report, $right);

        return $pdf->render((string) $report['title']);
    }

    /** @param array<string, mixed> $report */
    private function heading(PdfDocument $pdf, array $report, float $right): void
    {
        $pdf->text('AICOUNTLY INSIGHTS', self::MARGIN, 8.0, PdfDocument::FONT_BOLD, '0.09 0.47 0.01');
        $pdf->advance(16);
        $pdf->text($this->printable((string) $report['title']), self::MARGIN, 18.0, PdfDocument::FONT_BOLD);
        $pdf->advance(22);

        $scope = $report['scope'];
        $pdf->text($this->printable((string) $scope['company']), self::MARGIN, 11.0, PdfDocument::FONT_BOLD, '0.2 0.2 0.2');
        $pdf->advance(14);
        $pdf->text(
            $this->printable($scope['branch'] . '  ·  ' . $scope['financial_year']),
            self::MARGIN,
            9.5,
            PdfDocument::FONT_REGULAR,
            '0.35 0.35 0.35',
        );
        $pdf->advance(13);
        $pdf->text(
            $this->printable($report['period']['label'] . '  ·  ' . $report['period']['comparison_label']),
            self::MARGIN,
            9.5,
            PdfDocument::FONT_REGULAR,
            '0.35 0.35 0.35',
        );
        $pdf->advance(12);
        $pdf->rule(self::MARGIN, $right);
        $pdf->advance(14);
    }

    /** @param array<string, mixed> $report */
    private function provenance(PdfDocument $pdf, array $report, float $right): void
    {
        $lines = [
            'Generated ' . $report['generated_at'] . ' by ' . $this->printable((string) $report['generated_by']),
        ];

        if ($report['filters'] !== []) {
            $parts = [];
            foreach ($report['filters'] as $field => $value) {
                $parts[] = $field . ' = ' . $value;
            }
            $lines[] = 'Filters: ' . implode(', ', $parts);
        }

        foreach ($report['sources'] as $source) {
            $lines[] = $source['label'] . ': ' . $source['status_label']
                . ($source['fetched_at'] !== null ? ', fetched ' . $source['fetched_at'] : '');
        }

        foreach ($lines as $line) {
            $pdf->text($this->printable($line), self::MARGIN, 8.5, PdfDocument::FONT_REGULAR, '0.4 0.4 0.4');
            $pdf->advance(11);
        }

        $pdf->advance(6);
    }

    private function banner(PdfDocument $pdf, string $text, float $right): void
    {
        $pdf->fill(self::MARGIN, $pdf->y() - 4, $right - self::MARGIN, 18, '1 0.96 0.85');
        $pdf->text($this->printable($text), self::MARGIN + 6, 9.0, PdfDocument::FONT_BOLD, '0.53 0.33 0');
        $pdf->advance(24);
    }

    /** @param array<string, mixed> $report */
    private function table(PdfDocument $pdf, array $report, float $right): void
    {
        $columns = $report['columns'];
        if ($columns === []) {
            return;
        }

        $available = $right - self::MARGIN;
        // The label column takes the space the numbers do not need. Numbers are
        // right-aligned in fixed columns, which is what makes a money column
        // readable at a glance.
        $numeric = max(0, count($columns) - 1);
        $numericWidth = $numeric > 0 ? min(95.0, ($available * 0.62) / $numeric) : 0.0;
        $labelWidth = $available - ($numericWidth * $numeric);

        $drawHeader = function () use ($pdf, $columns, $labelWidth, $numericWidth, $right): void {
            $pdf->fill(self::MARGIN, $pdf->y() - 4, $right - self::MARGIN, 16, '0.91 0.96 0.89');
            $pdf->text($this->printable((string) $columns[0]['label']), self::MARGIN + 4, 9.0, PdfDocument::FONT_BOLD);
            for ($i = 1; $i < count($columns); $i++) {
                $columnRight = self::MARGIN + $labelWidth + ($numericWidth * $i);
                $pdf->textRight($this->printable((string) $columns[$i]['label']), $columnRight - 4, 9.0, PdfDocument::FONT_BOLD);
            }
            $pdf->advance(20);
        };

        $drawHeader();

        foreach ($report['rows'] as $row) {
            if (!$pdf->fits(24)) {
                $pdf->newPage();
                $drawHeader();
            }

            $pdf->text($this->printable((string) ($row['label'] ?? '')), self::MARGIN + 4, 9.0);

            for ($i = 1; $i < count($columns); $i++) {
                $key = (string) $columns[$i]['key'];
                $formatted = $row[$key . '_formatted'] ?? $row[$key] ?? null;
                $text = $formatted === null ? '—' : (string) $formatted;
                $columnRight = self::MARGIN + $labelWidth + ($numericWidth * $i);
                $pdf->textRight($this->printable($text), $columnRight - 4, 9.0);
            }

            $pdf->advance(16);
            $pdf->rule(self::MARGIN, $right, null, '0.93 0.95 0.93', 0.4);
            $pdf->advance(2);
        }

        if ($report['rows'] === []) {
            $pdf->text('No rows. The sources answered, and there is nothing in this period matching these filters.', self::MARGIN, 9.5, PdfDocument::FONT_REGULAR, '0.4 0.4 0.4');
            $pdf->advance(18);
        }
    }

    /** @param array<string, mixed> $report */
    private function notes(PdfDocument $pdf, array $report, float $right): void
    {
        $definitions = array_values(array_filter((array) ($report['metrics'] ?? [])));
        $warnings = (array) ($report['warnings'] ?? []);

        if ($definitions === [] && $warnings === []) {
            return;
        }

        if (!$pdf->fits(80)) {
            $pdf->newPage();
        }

        $pdf->advance(10);
        $pdf->rule(self::MARGIN, $right);
        $pdf->advance(16);
        $pdf->text('What these figures mean', self::MARGIN, 11.0, PdfDocument::FONT_BOLD);
        $pdf->advance(16);

        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            if (!$pdf->fits(40)) {
                $pdf->newPage();
            }
            $pdf->text($this->printable((string) $definition['label']), self::MARGIN, 9.0, PdfDocument::FONT_BOLD);
            $pdf->advance(12);
            foreach ($this->wrap((string) $definition['definition'], 110) as $line) {
                $pdf->text($this->printable($line), self::MARGIN, 8.5, PdfDocument::FONT_REGULAR, '0.35 0.35 0.35');
                $pdf->advance(10);
            }
            $pdf->text(
                $this->printable('Source: ' . $definition['owning_product'] . '  ·  definition version ' . $definition['formula_version']),
                self::MARGIN,
                8.0,
                PdfDocument::FONT_REGULAR,
                '0.5 0.5 0.5',
            );
            $pdf->advance(14);
        }

        if ($warnings !== []) {
            if (!$pdf->fits(40)) {
                $pdf->newPage();
            }
            $pdf->advance(6);
            $pdf->text('Notes and limitations', self::MARGIN, 11.0, PdfDocument::FONT_BOLD, '0.53 0.33 0');
            $pdf->advance(16);
            foreach ($warnings as $warning) {
                foreach ($this->wrap('• ' . (string) $warning, 110) as $line) {
                    if (!$pdf->fits(16)) {
                        $pdf->newPage();
                    }
                    $pdf->text($this->printable($line), self::MARGIN, 8.5, PdfDocument::FONT_REGULAR, '0.35 0.35 0.35');
                    $pdf->advance(10);
                }
                $pdf->advance(3);
            }
        }
    }

    /**
     * Text the base-fourteen fonts can actually draw.
     *
     * Anything outside WinAnsi — Devanagari, Tamil, an emoji — cannot be shown
     * with these fonts. Rather than dropping the row or printing mojibake, the
     * unprintable part is replaced with a marker that tells the reader exactly
     * what happened and where.
     */
    private function printable(string $value): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        if ($clean === '') {
            return '';
        }

        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $clean);
        if ($converted === false) {
            return '[name cannot be printed in this font]';
        }

        // TRANSLIT leaves a '?' where it could not find a substitute. A string
        // that is mostly question marks is a name that did not survive.
        $questionMarks = substr_count($converted, '?');
        if ($questionMarks > 0 && $questionMarks >= (int) ceil(mb_strlen($clean) / 2)) {
            return '[name cannot be printed in this font]';
        }

        return $converted;
    }

    /** @return list<string> */
    private function wrap(string $text, int $width): array
    {
        $wrapped = wordwrap($text, $width, "\n", true);

        return array_slice(explode("\n", $wrapped), 0, 8);
    }
}
