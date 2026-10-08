<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;

final class SettingsService
{
    public function __construct(private readonly SettingsRepository $repository)
    {
    }

    public function all(): array
    {
        return $this->repository->all();
    }

    public function updateFields(string $group,array $fields):void
    {
        $allowed=[
            'general'=>['app_name','timezone','date_format'],
            'sales_credit'=>['credit_limit_enforcement','default_payment_method','allow_rate_edit','allow_advance','gas_decimals','money_decimals'],
            'tax'=>['enabled','tax_name','rate_percent','applies_to_gas'],
            'printing'=>['paper_size','header_text','footer_text'],
            'cheques'=>['cheque_ledger_posting'],
            'code_generation'=>['cylinder_code_mode','cylinder_code_pattern','cylinder_seq_width','group_code_mode','group_code_prefix','group_code_width']
        ];
        if(!isset($allowed[$group]))throw new \InvalidArgumentException('Unsupported settings group.');
        foreach($fields as $key=>$value){
            if(!in_array($key,$allowed[$group],true))throw new \InvalidArgumentException('Unsupported setting: '.$key);
            $value=trim((string)$value);
            if($key==='credit_limit_enforcement'&&!in_array(strtoupper($value),['BLOCK','WARN'],true))throw new \InvalidArgumentException('Credit enforcement must be BLOCK or WARN.');
            if($key==='default_payment_method'&&!in_array(strtoupper($value),['CASH','ONLINE','CHEQUE'],true))throw new \InvalidArgumentException('Invalid default payment method.');
            if(in_array($key,['allow_rate_edit','allow_advance','enabled','applies_to_gas'],true)&&!in_array($value,['0','1'],true))throw new \InvalidArgumentException($key.' must be 0 or 1.');
            if(in_array($key,['gas_decimals','money_decimals','cylinder_seq_width','group_code_width'],true)&&(!ctype_digit($value)|| (int)$value<0 || (int)$value>12))throw new \InvalidArgumentException($key.' has an invalid value.');
            if($key==='paper_size'&&!in_array(strtoupper($value),['80MM','A4'],true))throw new \InvalidArgumentException('Paper size must be 80MM or A4.');
            if($key==='cheque_ledger_posting'&&!in_array(strtoupper($value),['ON_CLEARANCE','ON_RECEIPT'],true))throw new \InvalidArgumentException('Invalid cheque posting mode.');
            if($key==='rate_percent'&&(!is_numeric($value)||bccomp($value,'0',2)<0))throw new \InvalidArgumentException('Tax percentage cannot be negative.');
            $this->repository->set($group,$key,$value);
        }
    }

    public function updatePosSettings(array $transactionTypes, string $defaultType): void
    {
        $allowed = ['GAS_SALE', 'EMPTY_CYLINDER_SALE'];
        $types = array_values(array_unique(array_filter(
            array_map(static fn ($value): string => trim((string) $value), $transactionTypes),
            static fn (string $value): bool => $value !== ''
        )));

        if ($types === []) {
            throw new \InvalidArgumentException('Select at least one POS transaction type.');
        }

        foreach ($types as $type) {
            if (!in_array($type, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported POS transaction type.');
            }
        }

        if (!in_array($defaultType, $types, true)) {
            throw new \InvalidArgumentException('The default POS transaction type must be enabled.');
        }

        $this->repository->set('sales', 'pos_transaction_types', implode(',', $types));
        $this->repository->set('sales', 'pos_default_transaction_type', $defaultType);
    }
}
