<div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-1">Receipts</h1><p class="text-secondary mb-0">Customer receipts, including POS-linked receipts.</p></div></div>
<div class="row g-3">
<div class="col-lg-5"><div class="card shadow-sm"><div class="card-header"><strong>New Receipt</strong></div><div class="card-body"><form id="form">
<?= csrf_input() ?>
<label class="form-label">Date</label><input name="receipt_date" type="date" class="form-control mb-2" value="<?=e(date('Y-m-d'))?>">
<label class="form-label">Customer</label><select name="party_id" class="form-select mb-2"><?php foreach($customers as $c):?><option value="<?=e($c['id'])?>"><?=e($c['code'].' - '.$c['name'])?></option><?php endforeach;?></select>
<label class="form-label">Amount</label><input name="amount" class="form-control mb-2" inputmode="decimal" value="0.00">
<label class="form-label">Method</label><select name="method" id="method" class="form-select mb-2"><option>CASH</option><option>ONLINE</option><option>CHEQUE</option></select>
<label class="form-label">Counter ID (cash)</label><input name="counter_id" class="form-control mb-2" value="<?=e((string)($user['default_counter_id']??''))?>">
<div id="cheque" class="border rounded p-2 d-none"><input name="cheque[cheque_no]" class="form-control mb-2" placeholder="Cheque no"><input name="cheque[bank]" class="form-control mb-2" placeholder="Bank"><input name="cheque[cheque_date]" type="date" class="form-control"></div>
<button class="btn btn-primary mt-3">Post Receipt</button></form><div id="msg" class="mt-3"></div></div></div></div>
<div class="col-lg-7"><div class="card shadow-sm"><div class="card-header"><strong>Receipt History</strong></div><div class="card-body"><div class="table-responsive"><table class="table table-sm" id="t"><thead><tr><th>Doc</th><th>Date</th><th>Customer</th><th>Amount</th><th>Method</th><th>Source</th><th>Status</th><th></th></tr></thead><tbody></tbody></table></div></div></div></div>
</div>
<script>
$(function(){const b=<?=json_encode(url('/'),JSON_THROW_ON_ERROR)?>;
function load(){$.getJSON(b+'receipts/data').done(r=>{let x=$('#t tbody').empty();(r.data.rows||[]).forEach(v=>x.append('<tr><td>'+v.doc_no+'</td><td>'+v.receipt_date+'</td><td>'+v.customer_code+' - '+v.customer_name+'</td><td>'+Number(v.amount).toFixed(2)+'</td><td>'+v.method+'</td><td>'+v.source+'</td><td>'+v.status+'</td><td>'+(v.status==='POSTED'?'<button class="btn btn-sm btn-outline-danger void" data-id="'+v.id+'">Void</button>':'')+'</td></tr>')});}
$('#method').on('change',function(){$('#cheque').toggleClass('d-none',this.value!=='CHEQUE')});
$('#form').on('submit',function(e){e.preventDefault();let o=$(this).serializeArray(),d={};o.forEach(x=>{if(x.name.startsWith('cheque[')){d.cheque=d.cheque||{};d.cheque[x.name.slice(7,-1)]=x.value}else d[x.name]=x.value});d._csrf=$('meta[name="csrf-token"]').attr('content');$.post(b+'receipts',d).done(r=>{alert(r.message);if(r.ok){this.reset();load();}}).fail(x=>alert(x.responseJSON?.message||'Receipt failed'))});
$(document).on('click','.void',function(){let reason=prompt('Void reason:');if(!reason)return;$.post(b+'receipts/'+$(this).data('id')+'/void',{reason}).done(r=>{alert(r.message);load()}).fail(x=>alert(x.responseJSON?.message||'Void failed'))});load();});
</script>