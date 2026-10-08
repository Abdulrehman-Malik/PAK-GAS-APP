<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
<div><h1 class="h3 mb-1">Opening Stock</h1><p class="text-secondary mb-0">Create opening inventory manually or import validated Excel rows.</p></div>
<a class="btn btn-outline-secondary" href="<?=e(url('/opening-stock/template'))?>">Download Excel Template</a>
</div>
<div class="row g-3">
<div class="col-lg-8"><div class="card shadow-sm mb-3"><div class="card-body"><form id="form"><div class="row g-3">
<div class="col-md-3"><label class="form-label">Batch date</label><input name="batch_date" type="date" class="form-control" value="<?=e(date('Y-m-d'))?>" required></div>
<div class="col-md-3"><label class="form-label">Cylinder group</label><select name="group_id" class="form-select" required><?php foreach($groups as $g):?><option value="<?=$g['id']?>"><?=e($g['code'].' - '.$g['name'].' ('.$g['capacity_kg'].' kg)')?></option><?php endforeach;?></select></div>
<div class="col-md-2"><label class="form-label">Quantity</label><input name="quantity" type="number" min="1" class="form-control" value="1" required></div>
<div class="col-md-2"><label class="form-label">Actual gas kg</label><input name="actual_gas" class="form-control" inputmode="decimal" value="0.000" required></div>
<div class="col-md-2"><label class="form-label">Condition</label><select name="condition_code" class="form-select"><option>GOOD</option><option>DAMAGED</option></select></div>
<div class="col-md-3"><label class="form-label">Location</label><select name="location" id="location" class="form-select"><option value="SHOP">SHOP</option><option value="ISSUED">ISSUED</option></select></div>
<div class="col-md-5" id="customerWrap"><label class="form-label">Customer</label><select name="customer_id" class="form-select"><option value="">Select customer</option><?php foreach($customers as $c):?><option value="<?=$c['id']?>"><?=e($c['code'].' - '.$c['name'])?></option><?php endforeach;?></select></div>
<div class="col-md-3"><label class="form-label">Code mode</label><select name="code_mode" id="codeMode" class="form-select"><option>AUTO</option><option>MANUAL</option></select></div>
<div class="col-md-12" id="manualCodesWrap"><label class="form-label">Manual codes</label><textarea name="codes" class="form-control" rows="2" placeholder="One code per cylinder"></textarea></div>
<div class="col-12"><button class="btn btn-primary">Post Opening Stock</button></div>
</div></form></div></div>
<div class="card shadow-sm"><div class="card-header"><strong>Excel Import</strong></div><div class="card-body"><form id="importForm" enctype="multipart/form-data"><input type="file" name="file" accept=".xlsx" class="form-control mb-2" required><button class="btn btn-outline-primary">Validate Excel</button></form><div id="preview" class="mt-3"></div></div></div></div>
<div class="col-lg-4"><div class="card shadow-sm"><div class="card-body"><h5>Opening Stock History</h5><div class="table-responsive"><table class="table table-sm"><thead><tr><th>ID</th><th>Date</th><th>Cyl.</th><th>Status</th><th></th></tr></thead><tbody id="rows"></tbody></table></div></div></div></div>
</div>
<script>
$(function(){const b=<?=json_encode(url('/'),JSON_THROW_ON_ERROR)?>;let validRows=[];
$('#customerWrap').hide();$('#location').change(function(){$('#customerWrap').toggle(this.value==='ISSUED')});
$('#codeMode').change(function(){$('#manualCodesWrap').toggle(this.value==='MANUAL')}).trigger('change');
$('#form').submit(function(e){e.preventDefault();$.post(b+'opening-stock',$(this).serialize()).done(r=>{alert(r.message);if(r.ok){this.reset();$('#customerWrap').hide();$('#manualCodesWrap').show();load()}}).fail(x=>alert(x.responseJSON?.message||'Save failed'))});
function load(){$.getJSON(b+'opening-stock/data').done(r=>{let x=$('#rows').empty();(r.data.rows||[]).forEach(v=>x.append('<tr><td>'+v.id+'</td><td>'+v.batch_date+'</td><td>'+v.cylinder_count+'</td><td>'+v.status+'</td><td>'+(v.status==='POSTED'?'<button class="btn btn-sm btn-outline-danger void" data-id="'+v.id+'">Void</button>':'')+'</td></tr>'))})}
$('#importForm').submit(function(e){e.preventDefault();const f=new FormData(this);$.ajax({url:b+'opening-stock/import/preview',method:'POST',data:f,processData:false,contentType:false}).done(r=>{if(!r.ok){alert(r.message);return;}validRows=r.data.valid||[];const errors=r.data.errors||[];window.importToken=r.data.token||'';let h='<div class="mb-2">Rows: '+r.data.rows_total+' · Valid: '+validRows.length+' · Errors: '+errors.length+'</div>';if(errors.length){h+='<div class="alert alert-warning">'+errors.map(x=>'Row '+x.row+': '+x.errors.join(', ')).join('<br>')+'</div><div class="text-danger">Fix the Excel file and upload it again. Nothing can be committed while validation errors exist.</div>';}else if(validRows.length)h+='<button type="button" id="commitImport" class="btn btn-primary">Commit Import</button>';$('#preview').html(h)}).fail(x=>alert(x.responseJSON?.message||'Import failed'))});
$(document).on('click','#commitImport',function(){$.post(b+'opening-stock/import/commit',{token:window.importToken||''}).done(r=>{alert(r.message);if(r.ok){validRows=[];$('#preview').empty();$('#importForm')[0].reset();load()}}).fail(x=>alert(x.responseJSON?.message||'Commit failed'))});
$(document).on('click','.void',function(){let reason=prompt('Void reason:');if(!reason)return;$.post(b+'opening-stock/void',{id:$(this).data('id'),reason}).done(r=>{alert(r.message);load()}).fail(x=>alert(x.responseJSON?.message||'Void failed'))});load();});
</script>