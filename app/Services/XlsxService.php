<?php

declare(strict_types=1);

namespace App\Services;

final class XlsxService
{
    public function read(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP Zip extension is required for Excel import.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('Unable to open Excel file.');
        }

        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml)) {
            $xml = simplexml_load_string($sharedXml);
            if ($xml !== false) {
                foreach ($xml->si as $item) {
                    $parts = [];
                    foreach ($item->t as $t) {
                        $parts[] = (string) $t;
                    }
                    foreach ($item->r as $run) {
                        $parts[] = (string) $run->t;
                    }
                    $shared[] = implode('', $parts);
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!is_string($sheetXml)) {
            $zip->close();
            throw new \InvalidArgumentException('Excel workbook has no first worksheet.');
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            $zip->close();
            throw new \InvalidArgumentException('Excel worksheet is invalid.');
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                preg_match('/([A-Z]+)\d+/', $ref, $m);
                $column = $m[1] ?? 'A';
                $index = 0;
                for ($i = 0; $i < strlen($column); $i++) {
                    $index = ($index * 26) + (ord($column[$i]) - 64);
                }
                $index--;

                while (count($values) <= $index) {
                    $values[] = '';
                }

                $value = (string) $cell->v;
                if ((string) $cell['t'] === 's' && isset($shared[(int) $value])) {
                    $value = $shared[(int) $value];
                }
                $values[$index] = $value;
            }
            $rows[] = $values;
        }

        $zip->close();

        if ($rows === []) {
            return [];
        }

        $headers = array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            array_shift($rows)
        );

        $result = [];
        foreach ($rows as $row) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                if ($header !== '') {
                    $assoc[$header] = (string) ($row[$i] ?? '');
                }
            }
            if (array_filter($assoc, static fn ($value): bool => trim((string) $value) !== []) {
                $result[] = $assoc;
            }
        }

        return $result;
    }

    public function write(string $path, array $headers, array $rows = []): void
    {
        $this->writeWorkbook($path, [
            'Data' => [
                'headers' => $headers,
                'rows' => $rows,
            ],
        ]);
    }

    public function writeWorkbook(string $path, array $sheets): void
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP Zip extension is required for Excel export.');
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $sheetParts = [];
        $sheetRelationships = [];
        $overrides = [];
        $sheetId = 1;

        foreach ($sheets as $name => $definition) {
            $safeName = trim((string) $name) !== '' ? trim((string) $name) : 'Sheet' . $sheetId;
            $safeName = mb_substr($safeName, 0, 31);
            $headers = $definition['headers'] ?? [];
            $rows = $definition['rows'] ?? [];
            $sheetXml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">';

            foreach ($headers as $i => $header) {
                $col = $this->column($i + 1);
                $sheetXml .= '<c r="' . $col . '1" t="inlineStr"><is><t>' . $escape((string) $header) . '</t></is></c>';
            }
            $sheetXml .= '</row>';

            $rowNumber = 2;
            foreach ($rows as $row) {
                $sheetXml .= '<row r="' . $rowNumber . '">';
                foreach ($headers as $i => $header) {
                    $col = $this->column($i + 1);
                    $value = (string) ($row[$header] ?? '');
                    $sheetXml .= '<c r="' . $col . $rowNumber . '" t="inlineStr"><is><t>' . $escape($value) . '</t></is></c>';
                }
                $sheetXml .= '</row>';
                $rowNumber++;
            }

            $sheetXml .= '</sheetData></worksheet>';
            $target = 'worksheets/sheet' . $sheetId . '.xml';
            $sheetParts[$target] = $sheetXml;
            $rid = 'rId' . $sheetId;
            $sheetRelationships[] = '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . $target . '"/>';
            $overrides[] = '<Override PartName="/xl/' . $target . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetParts[$safeName] = $rid;
            $sheetId++;
        }

        $sheetId = 1;
        $sheetXml = '';
        foreach ($sheetParts as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'worksheets/')) {
                $sheetXml .= '<sheet name="' . $escape(array_keys($sheets)[$sheetId - 1] ?? ('Sheet' . $sheetId)) . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>';
                $sheetId++;
            }
        }

        $contentTypes = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . implode('', $overrides) . '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $workbook = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheetXml . '</sheets></workbook>';
        $workbookRels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxml.org/package/2006/relationships">' . implode('', $sheetRelationships) . '</Relationships>';

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create Excel file.');
        }

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        foreach ($sheetParts as $key => $value) {
            if (str_starts_with($key, 'worksheets/')) {
                $zip->addFromString('xl/' . $key, $value);
            }
        }
        $zip->close();
    }

    private function column(int $number): string
    {
        $column = '';
        while ($number > 0) {
            $number--;
            $column = chr(65 + ($number % 26)) . $column;
            $number = intdiv($number, 26);
        }
        return $column;
    }
}
