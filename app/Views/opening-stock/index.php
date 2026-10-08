<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
<div><h1 class="h3 mb-1">Opening Stock</h1><p class="text-secondary mb-0">Create and import the opening cylinder inventory.</p></div>
<a class="btn btn-outline-primary" href="<?=e(url('/opening-stock/import-template?type=opening_stock'))?>">Download Excel Template</a>
</div>
<div id="msg" class="alert d-none"></div>

<div class="card shadow-sm mb-3"><div class="card-body">
<form id="form"><div class="row g-3">
<div class="col-md-2"><label class="form-label">Batch date</label><input name="batch_date" type="date" class="form-control" required></div>
<div class="col-md-3"><label class="form-label">Cylinder group</label><select name="group_id" id="group" class="form-select" required></select></div>
<div class="col-md-1"><label class="form-label">Qty</label><input name="quantity" type="number" min="1" class="form-control" value="1" required></div>
<div class="col-md-2"><label class="form-label">Actual gas kg</label><input name="actual_gas" class="form-control" inputmode="decimal" value="0" required></div>
<div class="col-md-2"><label class="form-label">Condition</label><select name="condition_code" class="form-select"><option>GOOD</option><option>DAMAGED</option></select></div>
<div class="col-md-2"><label class="form-label">Location</label><select name="location" id="location" class="form-select"><option value="SHOP">SHOP</option><option value="ISSUED">ISSUED</option></select></div>
<div class="col-md-3" id="customerWrap"><label class="form-label">Customer</label><input id="customerSearch" class="form-control" placeholder="Search customer"><select name="customer_id" id="customer" class="form-select mt-1"></select></div>
<div class="col-md-3"><label class="form-label">Code mode</label><select name="code_mode" class="form-select"><option>AUTO</option><option>MANUAL</option></select></div>
<div class="col-md-6"><label class="form-label">Manual codes</label><input name="codes" class="form-control" placeholder="One code per cylinder, comma/space separated"></div>
<div class="col-12"><button class="btn btn-primary">Post Opening Stock</button></div>
</div></form>
</div></div>

<div class="card shadow-sm mb-3"><div class="card-header"><strong>Excel Import</strong></div><div class="card-body">
<form id="importForm" enctype="multipart/form-data" class="row g-2 align-items-end"><div class="col-md-7"><label class="form-label">XLSX file</label><input name="file" type="file" accept=".xlsx" class="form-control" required></div><input type="hidden" name="type" value="opening_stock"><div class="col-md-3"><button class="btn btn-outline-primary w-100">Preview Import</button></div></form>
<div id="preview" class="mt-3 d-none"><div id="previewSummary" class="alert alert-info"></div><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Row</th><th>Group</th><th>Qty</th><th>Gas</th><th>Location</th><th>Result</th></tr></thead><tbody id="previewRows"></tbody></table></div><button id="commitImport" class="btn btn-success" disabled>Commit Valid Rows</button></div>
</div></div>

<div class="card shadow-sm"><div class="card-body"><h5>Opening Stock History</h5><div class="table-responsive"><table class="table table-sm"><thead><tr><th>ID</th><th>Date</th><th>Source</th><th>Cylinders</th><th>Status</th><th>Created by</th><th>Action</th></tr></thead><tbody id="rows"></tbody></table></div></div></div>

<script>
$(function(){
 const b=<?=json_encode(url('/'))?>;const today=new Date().toISOString().slice(0,10);$('input[name=batch_date]').val(today);
 let validRows=[];function esc(v){return $('<div>').text(v??'').html();}function msg(t,c='success'){$('#msg').removeClass('d-none alert-success alert-danger').addClass('alert-'+c).text(t);}
 $.getJSON(b+'opening-stock/groups',r=>{const s=$('#group').empty();(r.data||[]).forEach(x=>s.append(new Option(x.code+' - '+x.name+' ('+x.capacity_kg+' kg)',x.id)));});
 $('#location').on('change',function(){$('#customerWrap').toggleClass('d-none',this.value!=='ISSUED');}).trigger('change');
 $('#customerSearch').on('input',function(){const q=this.value.trim();if(q.length<2)return;$.getJSON(b+'opening-stock/customers',{q},r=>{$('#customer').empty();(r.data||[]).forEach(x=>$('#customer').append(new Option(x.code+' - '+x.name,x.id)));});});
 $('#form').on('submit',function(e){e.preventDefault();$.post(b+'opening-stock',$(this).serialize()).done(r=>{msg(r.message,r.ok?'success':'danger');if(r.ok){this.reset();$('input[name=batch_date]').val(today);load();}}).fail(x=>msg(x.responseJSON?.message||'Save failed','danger'));});
 function load(){$.getJSON(b+'opening-stock/data',r=>{const x=$('#rows').empty();(r.data.rows||[]).forEach(a=>x.append('<tr><td>'+a.id+'</td><td>'+esc(a.batch_date)+'</td><td>'+esc(a.source)+'</td><td>'+a.cylinder_count+'</td><td>'+esc(a.status)+'</td><td>'+esc(a.created_by_name||'')+'</td><td>'+(a.status==='POSTED'?'<button class="btn btn-sm btn-outline-danger void" data-id="'+a.id+'">Void</button>':'Voided')+'</td></tr>'));});}
 $('#rows').on('click','.void',function(){const reason=prompt('Void reason');if(!reason)return;$.post(b+'opening-stock/void',{id:$(this).data('id'),reason}).done(r=>{msg(r.message,r.ok?'success':'danger');if(r.ok)load();});});
 $('#importForm').on('submit',function(e){e.preventDefault();const fd=new FormData(this);$.ajax({url:b+'opening-stock/import-preview',method:'POST',data:fd,processData:false,contentType:false}).done(r=>{if(!r.ok){msg(r.message,'danger');return;}validRows=r.data.rows||[];const errors=r.data.errors||[];$('#previewSummary').text('Rows: '+r.data.rows_total+' | Valid: '+r.data.rows_ok+' | Errors: '+r.data.rows_error);const body=$('#previewRows').empty();validRows.forEach(a=>body.append('<tr><td>Valid</td><td>'+esc(a.group_code)+'</td><td>'+esc(a.quantity)+'</td><td>'+esc(a.actual_gas)+'</td><td>'+esc(a.location)+'</td><td class="text-success">Ready</td></tr>'));errors.forEach(a=>body.append('<tr><td>'+a.row+'</td><td>'+esc(a.data.group_code||'')+'</td><td>'+esc(a.data.quantity||'')+'</td><td>'+esc(a.data.actual_gas||'')+'</td><td>'+esc(a.data.location||'')+'</td><td class="text-danger">'+esc(a.message)+'</td></tr>'));$('#preview').removeClass('d-none');$('#commitImport').prop('disabled',validRows.length===0||errors.length>0);}).fail(x=>msg(x.responseJSON?.message||'Import preview failed','danger'));});
 $('#commitImport').click(function(){if(!validRows.length)return;if(!confirm('Commit '+validRows.length+' validated rows?'))return;$.post(b+'opening-stock/import-commit',{type:'opening_stock',rows_json:JSON.stringify(validRows)}).done(r=>{msg(r.message,r.ok?'success':'danger');if(r.ok){validRows=[];$('#preview').addClass('d-none');load();}});});
 load();
});
</script>