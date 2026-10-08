<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

final class RateRepository
{
    public function __construct(private readonly DB $db){}

    public function paginate(int $groupId,string $date,int $limit,int $offset):array
    {
        $limit=min(100,max(10,$limit));
        $offset=max(0,$offset);
        $where='1=1';
        $params=[];

        if($groupId>0){
            $where.=' AND r.group_id=:group_id';
            $params['group_id']=$groupId;
        }
        if($date!==''){
            $where.=' AND r.effective_date=:effective_date';
            $params['effective_date']=$date;
        }

        $total=(int)($this->db->fetchOne(
            "SELECT COUNT(*) c FROM rates r WHERE {$where}",
            $params
        )['c']??0);

        $rows=$this->db->fetchAll(
            "SELECT r.*,cg.code group_code,cg.name group_name
             FROM rates r
             JOIN cylinder_groups cg ON cg.id=r.group_id
             WHERE {$where}
             ORDER BY effective_date DESC,cg.name
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return ['rows'=>$rows,'total'=>$total];
    }

    public function create(array $d):int
    {
        $this->db->execute(
            'INSERT INTO rates(group_id,effective_date,gas_rate,cylinder_price,active,notes,created_by,updated_by)
             VALUES(:group_id,:effective_date,:gas_rate,:cylinder_price,1,:notes,:uid,:uid)',
            $d
        );
        return $this->db->lastInsertId();
    }
}
