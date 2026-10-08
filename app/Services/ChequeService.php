<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class ChequeService
{
    public function __construct(private readonly DB $db, private readonly LedgerService $ledger, private readonly AuditService $audit)
    {
    }

    public function clear(int $id, string $date, int $userId): void
    {
        $this->db->transaction(function () use ($id, $date, $userId): void {
            $c = $this->db->fetchOne('SELECT * FROM cheques WHERE id=:id FOR UPDATE', ['id'=>$id]);
            if (!$c || $c['status'] !== 'PENDING') {
                throw new InvalidArgumentException('Cheque is not pending.');
            }
            if ($date === '') {
                $date = date('Y-m-d');
            }

            if ($c['receipt_id']) {
                $receipt = $this->db->fetchOne('SELECT * FROM receipts WHERE id=:id AND status=\'POSTED\' FOR UPDATE', ['id'=>$c['receipt_id']]);
                if (!$receipt) throw new InvalidArgumentException('Linked receipt is not available.');
                if ($this->ledger->entriesForDocument('RECEIPT',(int)$receipt['id']) === []) {
                    $this->ledger->post((int)$receipt['party_id'], $date, 'RECEIPT', (int)$receipt['id'], '0.00', (string)$receipt['amount'], 'Cleared cheque '.$c['cheque_no'], $userId);
                }
            } elseif ($c['payment_id']) {
                $payment = $this->db->fetchOne('SELECT * FROM payments WHERE id=:id AND status=\'POSTED\' FOR UPDATE', ['id'=>$c['payment_id']]);
                if (!$payment) throw new InvalidArgumentException('Linked payment is not available.');
                if ($this->ledger->entriesForDocument('PAYMENT',(int)$payment['id']) === []) {
                    $this->ledger->post((int)$payment['party_id'], $date, 'PAYMENT', (int)$payment['id'], (string)$payment['amount'], '0.00', 'Cleared cheque '.$c['cheque_no'], $userId);
                }
            } else {
                throw new InvalidArgumentException('Cheque is not linked to a receipt or payment.');
            }

            $this->db->execute('UPDATE cheques SET status=\'CLEARED\',cleared_date=:date WHERE id=:id',['date'=>$date,'id'=>$id]);
            $this->audit->record($userId,'CLEAR','cheques',$id,['status'=>'PENDING'],['status'=>'CLEARED','cleared_date'=>$date],null);
        });
    }

    public function bounce(int $id, int $userId, string $reason): void
    {
        $reason=trim($reason);
        if($reason==='') throw new InvalidArgumentException('Bounce reason is required.');
        $this->db->transaction(function()use($id,$userId,$reason):void{
            $c=$this->db->fetchOne('SELECT * FROM cheques WHERE id=:id FOR UPDATE',['id'=>$id]);
            if(!$c||$c['status']!=='PENDING') throw new InvalidArgumentException('Cheque is not pending.');
            $this->db->execute('UPDATE cheques SET status=\'BOUNCED\',bounced_reason=:reason WHERE id=:id',['reason'=>$reason,'id'=>$id]);
            $this->audit->record($userId,'BOUNCE','cheques',$id,['status'=>'PENDING'],['status'=>'BOUNCED','reason'=>$reason],null);
        });
    }
}
