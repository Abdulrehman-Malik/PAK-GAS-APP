<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($receipt['doc_no'])?> · <?=e($appName)?></title>
<style>
@page{size:80mm auto;margin:4mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;font-size:12px;margin:0;width:72mm}h1,h2,p{margin:0}.center{text-align:center}.row{display:flex;justify-content:space-between;gap:8px}.line{border-top:1px dashed #000;margin:6px 0}.amount{font-size:16px;font-weight:700}.small{font-size:10px}@media print{.print-button{display:none}}
</style>
</head>
<body>
<div class="center"><h2><?=e($appName)?></h2><div class="small">Receipt Voucher</div></div>
<div class="line"></div>
<div>Receipt: <strong><?=e($receipt['doc_no'])?></strong></div>
<div>Date: <?=e($receipt['receipt_date'])?></div>
<div>Customer: <?=e($receipt['customer_code'].' - '.$receipt['customer_name'])?></div>
<div>Method: <?=e($receipt['method'])?></div>
<div class="line"></div>
<div class="row"><span>Amount</span><strong><?=e(money((string)$receipt['amount']))?></strong></div>
<div class="row amount"><span>Received</span><span><?=number_format((float)$receipt['amount'],2)?></span></div>
<div class="line"></div>
<div class="center small">Thank you</div>
<button class="print-button" onclick="window.print()">Print</button>
<script>window.addEventListener('load',function(){setTimeout(function(){window.print()},150)})</script>
</body>
</html>