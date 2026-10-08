<?php

declare(strict_types=1);

namespace App\Services;

final class XlsxService
{
    public function write(array $rows,string $path,string $sheetName='Sheet1'):void
    {
        $dir=dirname($path);
        if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new \RuntimeException('Unable to create export directory.');
        $zip=new \ZipArchive();
        if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Unable to create XLSX file.');

        $esc=static fn(string $v):string=>htmlspecialchars($v,ENT_XML1|ENT_QUOTES,'UTF-8');
        $sheetRows='';
        foreach(array_values($rows) as $rowIndex=>$row){
            $excelRow=$rowIndex+1;
            $sheetRows.='<row r="'.$excelRow.'">';
            foreach(array_values($row) as $columnIndex=>$value){
                $cell=$this->columnName($columnIndex).$excelRow;
                $value=(string)$value;
                $sheetRows.='<c r="'.$cell.'" t="inlineStr"><is><t>'.$esc($value).'</t></is></c>';
            }
            $sheetRows.='</row>';
        }

        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');

        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$esc($sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/worksheets/sheet1.xml','<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();
    }

    public function read(string $path):array
    {
        if(!is_file($path))throw new \InvalidArgumentException('Uploaded XLSX file not found.');
        $zip=new \ZipArchive();
        if($zip->open($path)!==true)throw new \InvalidArgumentException('Unable to open XLSX file.');
        $xml=$zip->getFromName('xl/worksheets/sheet1.xml');
        $sharedXml=$zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        if($xml===false)throw new \InvalidArgumentException('XLSX worksheet was not found.');

        $shared=[];
        if($sharedXml!==false){
            $sx=@simplexml_load_string($sharedXml);
            if($sx!==false){
                $namespaces=$sx->getNamespaces(true);
                $main=$sx->children($namespaces['']??null);
                foreach($main->si as $si){
                    $parts=[];
                    $collect=function($node)use(&$parts):void{
                        foreach($node->children() as $child){
                            if($child->getName()==='t')$parts[]=(string)$child;
                            else $collect($child);
                        }
                    };
                    $collect($si);
                    $shared[]=implode('',$parts);
                }
            }
        }

        $sx=@simplexml_load_string($xml);
        if($sx===false)throw new \InvalidArgumentException('Invalid XLSX worksheet XML.');
        $ns=$sx->getNamespaces(true);
        $sheet=$sx->children($ns['']??null);
        $rows=[];
        foreach($sheet->sheetData->row as $row){
            $values=[];
            foreach($row->c as $cell){
                $attrs=$cell->attributes();
                $ref=(string)($attrs['r']??'A1');
                preg_match('/^([A-Z]+)/',$ref,$m);
                $col=$this->columnIndex($m[1]??'A');
                $type=(string)($attrs['t']??'');
                $value='';
                if($type==='inlineStr'){
                    $parts=[];
                    foreach($cell->is->t as $t)$parts[]=(string)$t;
                    $value=implode('',$parts);
                }else{
                    $v=(string)($cell->v??'');
                    $value=$type==='s'&&isset($shared[(int)$v])?$shared[(int)$v]:$v;
                }
                $values[$col]=$value;
            }
            if($values!==[]){
                $max=max(array_keys($values));
                $dense=[];
                for($i=0;$i<=$max;$i++)$dense[]=$values[$i]??'';
                $rows[]=$dense;
            }
        }
        return $rows;
    }

    private function columnName(int $index):string
    {
        $name='';
        while($index>=0){$name=chr(($index%26)+65).$name;$index=intdiv($index,26)-1;}
        return $name;
    }

    private function columnIndex(string $column):int
    {
        $index=0;
        for($i=0,$len=strlen($column);$i<$len;$i++)$index=$index*26+(ord($column[$i])-64);
        return $index-1;
    }
}
