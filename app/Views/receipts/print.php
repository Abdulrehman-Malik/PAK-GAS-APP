<?php
$r=$data['receipt'];$lines=$data['lines'];
$printing=$GLOBALS['db']->fetchAll("SELECT setting_key,setting_value FROM settings WHERE setting_group='printing'");
$settings=[];foreach($printing as $s)$settings[$s['setting_key']]=$s['setting_value'];
?>
<div class="center">
    <div class="bold" style="font-size:18px"><?=e($settings['header_text']??'Pak Gas POS')?></div>
    <div class="small"><?=e($settings['footer_text']??'')?></div>
</div>
<div class="row"><span>Document</span><span class="bold"><?=e($r['sale_doc']??$r['doc_no']??'')?></span></div>
<div class="row"><span>Date</span><span><?=e($r['sale_date']??$r['receipt_date']??$r['txn_date']??'')?></span></div>
<div class="row"><span>Customer</span><span><?=e(($r['customer_code']??'').' - '.($r['customer_name']??''))?></span></div>
<?php if($lines): ?>
    <?php foreach($lines as $l): ?>
        <div class="row">
            <span><?=e($l['line_type'])?> <?=e($l['cylinder_code'])?> / <?=e($l['group_name'])?></span>
            <span><?=bcadd((string)$l['amount'],'0.00',2)?></span>
        </div>
        <div class="small"><?=bcadd((string)$l['gas_kg'],'0.000',3)?> kg × <?=bcadd((string)$l['rate'],'0.00',2)?></div>
    <?php endforeach; ?>
<?php endif; ?>
<?php if(isset($r['net_amount'])): ?><div class="row bold"><span>Net</span><span><?=bcadd((string)$r['net_amount'],'0.00',2)?></span></div><?php endif; ?>
<?php if(isset($r['amount'])): ?><div class="row"><span>Receipt</span><span><?=bcadd((string)$r['amount'],'0.00',2)?></span></div><?php endif; ?>
<?php if(isset($r['received_amount'])): ?><div class="row"><span>Received</span><span><?=bcadd((string)$r['received_amount'],'0.00',2)?></span></div><?php endif; ?>
<?php if(isset($r['balance_after'])): ?><div class="row bold"><span>Balance</span><span><?=bcadd((string)$r['balance_after'],'0.00',2)?></span></div><?php endif; ?>
<?php if(isset($r['method'])): ?><div class="row"><span>Method</span><span><?=e($r['method'])?></span></div><?php endif; ?>
<div class="center small" style="margin-top:8px">Printed <?=e(date('Y-m-d H:i:s'))?></div>
