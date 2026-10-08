<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class DocNumberService
{
    public function __construct(private readonly DB $db){}

    public function next(string $type): string
    {
        $row=$this->db->fetchOne(
            'SELECT id,prefix,last_value,width FROM doc_sequences WHERE doc_type=:type ORDER BY id LIMIT 1 FOR UPDATE',
            ['type'=>$type]
        );
        if(!$row){
            $prefix=strtoupper(substr($type,0,3)).'-';
            $this->db->execute(
                'INSERT INTO doc_sequences(doc_type,prefix,width,last_value) VALUES(:type,:prefix,6,0)',
                ['type'=>$type,'prefix'=>$prefix]
            );
            $row=$this->db->fetchOne(
                'SELECT id,prefix,last_value,width FROM doc_sequences WHERE doc_type=:type ORDER BY id LIMIT 1 FOR UPDATE',
                ['type'=>$type]
            );
        }

        $n=(int)$row['last_value']+1;
        $this->db->execute(
            'UPDATE doc_sequences SET last_value=:value WHERE id=:id',
            ['value'=>$n,'id'=>$row['id']]
        );

        $width=max(1,min(12,(int)($row['width']??6)));
        return (string)$row['prefix'].str_pad((string)$n,$width,'0',STR_PAD_LEFT);
    }

    public function definitions():array
    {
        $types=['SALE','RECEIPT','PURCHASE','PAYMENT','EXPENSE'];
        $rows=[];
        foreach($types as $type){
            $row=$this->db->fetchOne('SELECT * FROM doc_sequences WHERE doc_type=:type',['type'=>$type]);
            if(!$row){
                $this->db->execute(
                    'INSERT INTO doc_sequences(doc_type,prefix,width,last_value) VALUES(:type,:prefix,6,0)',
                    ['type'=>$type,'prefix'=>strtoupper(substr($type,0,3)).'-']
                );
                $row=$this->db->fetchOne('SELECT * FROM doc_sequences WHERE doc_type=:type',['type'=>$type]);
            }
            $rows[]=$row;
        }
        return $rows;
    }

    public function updateDefinition(string $type,string $prefix,int $width,int $nextValue):void
    {
        $type=strtoupper(trim($type));
        if(!in_array($type,['SALE','RECEIPT','PURCHASE','PAYMENT','EXPENSE'],true))throw new \InvalidArgumentException('Unsupported document type.');
        $prefix=trim($prefix);
        if($prefix==='')throw new \InvalidArgumentException('Document prefix is required.');
        if($width<1||$width>12)throw new \InvalidArgumentException('Document width must be 1 to 12.');
        if($nextValue<1)throw new \InvalidArgumentException('Next document number must be at least 1.');

        $this->db->execute(
            'UPDATE doc_sequences SET prefix=:prefix,width=:width,last_value=:last_value WHERE doc_type=:type',
            ['prefix'=>$prefix,'width'=>$width,'last_value'=>$nextValue-1,'type'=>$type]
        );
    }
}
