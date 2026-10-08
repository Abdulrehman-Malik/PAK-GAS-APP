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
                    foreach ($item->t as $t) $parts[] = (string)$t;
                    foreach ($item->r as $run) $parts[] = (string)$run->t;
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
                $ref = (string)$cell['r'];
                preg_match('/([A-Z]+)\d+/', $ref, $m);
                $column = $m[1] ?? 'A';
                $index = 0;
                for ($i=0;$i<strlen($column);$i++) $index=$index*26+(ord($column[$i])-64);
                $index--;
                while (count($values) <= $index) $values[]='';
                $value = (string)$cell->v;
                if ((string)$cell['t'] === 's' && isset($shared[(int)$value]) ) $value=$shared[(int)$value];
                $values[$index]=$value;
            }
            $rows[]=$values;
        }
        $zip->close();

        if ($rows === []) return [];
        $headers=array_map(static fn($v)=>strtolower(trim((string)$v)),array_shift($rows));
        $result=[];
        foreach($rows as $row){
            $assoc=[];
            foreach($headers as $i=>$header){if($header!=='')$assoc[$header]=(string)($row[$i]??'');}
            if(array_filter($assoc,static fn($v)=>trim((string)$v)!=='')!==[])$result[]=$assoc;
        }
        return $result;
    }

    public function write(string $path, array $headers, array $rows = []): void
    {
        if (!class_exists('ZipArchive')) throw new \RuntimeException('PHP Zip extension is required for Excel export.');
        $escape = static fn(string $v): string => htmlspecialchars($v, ENT_XML1|ENT_COMPAT, 'UTF-8');
        $sheet='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">';
        foreach($headers as $i=>$h){$col=$this->column($i+1);$sheet.='<c r="'.$col.'1" t="inlineStr"><is><t>'.$escape((string)$h).'</t></is></c>';}
        $sheet.='</row>';
        $r=2;
        foreach($rows as $row){
            $sheet.='<row r="'.$r.'">';
            foreach($headers as $i=>$h){$col=$this->column($i+1);$v=(string)($row[$h]??'');$sheet.='<c r="'.$col.$r.'" t="inlineStr"><is><t>'.$escape($v).'</t></is></c>';}
            $sheet.='</row>'; $r++;
        }
        $sheet.='</sheetData></worksheet>';
        $contentTypes='<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
        $rels='<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $workbook='<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $workbookRels='<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';
        $zip=new \ZipArchive();if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Unable to create Excel file.');
        $zip->addFromString('[Content_Types].xml',$contentTypes);$zip->addFromString('_rels/.rels',$rels);$zip->addFromString('xl/workbook.xml',$workbook);$zip->addFromString('xl/_rels/workbook.xml.rels',$workbookRels);$zip->addFromString('xl/worksheets/sheet1.xml',$sheet);$zip->close();
    }

    private function column(int $n): string { $s=''; while($n>0){$n--; $s=chr(65+($n%26)).$s; $n=intdiv($n,26);} return $s; }
}
