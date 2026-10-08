<?php
$config=App\Core\Config::load(base_path());
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($pageTitle??$config['app_name'])?></title>
<style>
@page{size:80mm auto;margin:3mm}
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;font-size:12px;color:#111}
.print-wrap{width:72mm;margin:0 auto}
.center{text-align:center}
.row{display:flex;justify-content:space-between;gap:8px;border-bottom:1px dotted #999;padding:3px 0}
.small{font-size:10px}
.bold{font-weight:700}
@media print{.no-print{display:none!important}}
</style>
</head>
<body>
<div class="print-wrap">
<?= $content ?>
</div>
<script>window.addEventListener('load',()=>window.print());</script>
</body>
</html>
