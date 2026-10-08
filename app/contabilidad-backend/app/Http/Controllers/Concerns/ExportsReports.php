<?php

namespace App\Http\Controllers\Concerns;

/**
 * CSV y PDF comparten la misma tabla de encabezados + filas ya formateadas
 * como texto; solo cambia el empaquetado. Evita repetir el armado de la
 * grilla en cada método de reporte (mismo patrón que ReportController, pero
 * sin duplicarlo por cada tipo nuevo).
 */
trait ExportsReports
{
    /** $bom = true antepone la marca UTF-8 para que Excel muestre bien las tildes (los reportes de siempre no la llevan). */
    private function csvResponse(array $headers, array $rows, string $filename, bool $bom = false)
    {
        $csv = ($bom ? "\xEF\xBB\xBF" : '') . $this->csvLine($headers);
        foreach ($rows as $row) {
            // Una fila que es solo texto es el título de una sección del reporte
            $csv .= $this->csvLine(is_array($row) ? $row : [$row]);
        }

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '.csv"');
    }

    private function csvLine(array $cols): string
    {
        return implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $cols)) . "\n";
    }

    private function pdfResponse(string $titulo, array $headers, array $rows, string $filename, ?array $totalRow = null)
    {
        $thead = implode('', array_map(fn ($h) => "<th>{$h}</th>", $headers));
        $tbody = '';
        foreach ($rows as $row) {
            if (! is_array($row)) {
                // Título de una sección: ocupa todo el ancho de la tabla
                $tbody .= '<tr class="seccion"><td colspan="' . count($headers) . '">' . htmlspecialchars((string) $row) . '</td></tr>';
                continue;
            }
            $tbody .= '<tr>' . implode('', array_map(fn ($c) => "<td>{$c}</td>", $row)) . '</tr>';
        }
        $tfoot = '';
        if ($totalRow) {
            $tfoot = '<tr class="total">' . implode('', array_map(fn ($c) => "<td>{$c}</td>", $totalRow)) . '</tr>';
        }

        $html = "<!DOCTYPE html><html><head><style>
            body { font-family: Arial, sans-serif; font-size: 9px; }
            h1 { text-align: center; font-size: 14px; margin-bottom: 2px; }
            h2 { text-align: center; font-size: 10px; color: #666; margin-top: 0; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th { background: #1e5bb8; color: #fff; padding: 5px 7px; text-align: left; font-size: 8.5px; }
            td { padding: 4px 7px; border-bottom: 1px solid #ddd; font-size: 8.5px; }
            .total td { font-weight: bold; border-top: 2px solid #333; }
            .seccion td { background: #e8eef7; font-weight: bold; padding-top: 6px; }
            </style></head><body>
            <h1>" . strtoupper($titulo) . "</h1>
            <h2>Generado: " . now()->format('d/m/Y H:i') . "</h2>
            <table><thead><tr>{$thead}</tr></thead><tbody>{$tbody}{$tfoot}</tbody></table>
            </body></html>";

        return \Barryvdh\DomPDF\Facade\Pdf::loadHtml($html)
            ->setPaper('letter', 'landscape')
            ->download($filename . '.pdf');
    }
}
