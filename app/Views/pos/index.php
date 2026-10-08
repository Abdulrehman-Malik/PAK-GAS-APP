<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h1 class="h3 mb-1">Point of Sale</h1><p class="text-secondary mb-0">Issue gas, sell cylinders, receive returns and take payment in one transaction.</p></div>
    <a class="btn btn-outline-secondary" href="<?=e(url('/sales-history'))?>">Sales History</a>
</div>
<div id="posError" class="alert alert-danger d-none" role="alert"></div>

<div class="card shadow-sm mb-3">
<div class="card-body">
<div class="row g-2">
<div class="col-md-2"><label class="form-label" for="transactionType">Transaction Type</label><select id="transactionType" class="form-select"></select></div>
<div class="col-md-3"><label class="form-label">Customer search</label><input id="customerSearch" class="form-control" placeholder="Code, name or phone" autocomplete="off"><select id="customer" class="form-select mt-1"></select></div>
<div class="col-md-2"><label class="form-label">Transaction Date</label><input id="txnDate" type="date" class="form-control" value="<?=e($today)?>"></div>
<div class="col-md-2"><label class="form-label">Counter ID</label><input id="counterId" class="form-control" inputmode="numeric" value="<?=e((string)($user['default_counter_id']??''))?>"></div>
<div class="col-md-1"><label class="form-label">Method</label><select id="method" class="form-select"><option value="CASH">Cash</option><option value="ONLINE">Online</option><option value="CHEQUE">Cheque</option></select></div>
<div class="col-md-2"><label class="form-label">Received Now</label><input id="received" class="form-control" inputmode="decimal" value="0.00"></div>
</div>
<div id="customerInfo" class="alert alert-light border mt-3 mb-0 d-none"></div>
<div id="chequeFields" class="row g-2 mt-2 d-none">
<div class="col-md-4"><input id="chequeNo" class="form-control" placeholder="Cheque number"></div>
<div class="col-md-4"><input id="chequeBank" class="form-control" placeholder="Bank"></div>
<div class="col-md-4"><input id="chequeDate" type="date" class="form-control" value="<?=e($today)?>"></div>
</div>
</div>
</div>

<div class="card shadow-sm mb-3">
<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
<strong>Section A — Issue / Sell</strong>
<div class="d-flex gap-2">
<select id="group" class="form-select form-select-sm" style="min-width:210px"><option value="0">All groups</option></select>
<button type="button" id="receiveBack" class="btn btn-sm btn-outline-primary">Receive back cylinders</button>
</div>
</div>
<div class="card-body"><div id="cylinders" class="row g-2"></div></div>
</div>

<div class="card shadow-sm mb-3">
<div class="card-header"><strong>Transaction Lines</strong></div>
<div class="card-body">
<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Type</th><th>Cylinder</th><th>Gas kg</th><th>Rate</th><th>Cylinder Price</th><th>Amount/Credit</th><th></th></tr></thead><tbody id="lineRows"></tbody></table></div>
</div>
</div>

<div class="card shadow-sm">
<div class="card-body">
<div class="row g-2 text-end">
<div class="col-md-3">Gas issue <strong id="issueTotal">0.00</strong></div>
<div class="col-md-3">Cylinder sales <strong id="soldTotal">0.00</strong></div>
<div class="col-md-3">Return credit <strong id="returnTotal">0.00</strong></div>
<div class="col-md-3">Tax <strong id="taxTotal">0.00</strong></div>
<div class="col-md-4">Net <strong id="netTotal">0.00</strong></div>
<div class="col-md-4">Previous balance <strong id="previousBalance">0.00</strong></div>
<div class="col-md-4">New balance <strong id="newBalance">0.00</strong></div>
</div>
<div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
<button type="button" id="clear" class="btn btn-outline-secondary">Clear</button>
<button type="button" id="savePrint" class="btn btn-outline-primary">Save & Print</button>
<button type="button" id="save" class="btn btn-primary">Save</button>
</div>
</div>
</div>

<div class="modal fade" id="returnModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Receive Back Cylinders</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div id="returnCards" class="row g-2"></div></div>
<div class="modal-footer"><button type="button" class="btn btn-primary" id="addReturns">Add Selected Returns</button></div>
</div></div>
</div>

<script>
$(function(){
const base=<?=json_encode(url('/'))?>;
const returnModal=new bootstrap.Modal(document.getElementById('returnModal'));
const issueLines=[];const returnLines=[];let lastResult=null;

function esc(v){return $('<div>').text(v??'').html();}
function error(t){$('#posError').text(t||'Unexpected error.').removeClass('d-none');}
function clearError(){$('#posError').addClass('d-none').text('');}
function type(){return $('#transactionType').val()||'GAS_SALE';}

function updateBalanceBar(){
 const id=$('#customer').val();
 if(!id){$('#customerInfo').addClass('d-none');return;}
 $.getJSON(base+'pos/info',{customer_id:id}).done(r=>{
   if(!r.ok){error(r.message);return;}
   const d=r.data,p=d.party;
   $('#customerInfo').html(
     '<strong>'+esc(p.code)+' - '+esc(p.name)+'</strong>' +
     ' &nbsp; | &nbsp; Balance: <strong>'+Number(d.balance).toFixed(2)+'</strong>' +
     ' &nbsp; | &nbsp; Credit limit: <strong>'+Number(p.credit_limit).toFixed(2)+'</strong>' +
     ' &nbsp; | &nbsp; Cylinders held: <strong>'+d.cylinder_count+'</strong> ('+Number(d.cylinder_gas).toFixed(3)+' kg)' +
     ' &nbsp; | &nbsp; Pending cheques: <strong>'+d.pending_cheque_count+'</strong> ('+Number(d.pending_cheque_amount).toFixed(2)+')'
   ).removeClass('d-none');
   $('#previousBalance').text(Number(d.balance).toFixed(2));
   recalc();
 });
}

function recalc(){
 let issue=0,sold=0,ret=0,tax=0;
 issueLines.forEach(l=>{if(l.type==='ISSUE')issue+=Number(l.gas)*Number(l.rate);else sold+=l.type==='SELL_EMPTY'?Number(l.cp):(Number(l.gas)*Number(l.rate)+Number(l.cp));});
 returnLines.forEach(l=>{ret+=Number(l.gas)*Number(l.rate);});
 const net=issue+sold-ret;
 const prev=Number($('#previousBalance').text()||0);
 const received=Number($('#received').val()||0);
 $('#issueTotal').text(issue.toFixed(2));$('#soldTotal').text(sold.toFixed(2));$('#returnTotal').text(ret.toFixed(2));$('#taxTotal').text(tax.toFixed(2));$('#netTotal').text(net.toFixed(2));$('#newBalance').text((prev+net-received).toFixed(2));
 renderLines();
}

function renderLines(){
 const rows=$('#lineRows').empty();
 issueLines.forEach((l,i)=>rows.append('<tr><td>'+esc(l.type)+'</td><td>'+esc(l.code)+'<div class="small text-secondary">'+esc(l.group)+'</div></td><td>'+Number(l.gas).toFixed(3)+'</td><td>'+Number(l.rate).toFixed(2)+'</td><td>'+Number(l.cp).toFixed(2)+'</td><td>'+Number(l.amount).toFixed(2)+'</td><td><button class="btn btn-sm btn-outline-danger removeIssue" data-i="'+i+'">Remove</button></td></tr>'));
 returnLines.forEach((l,i)=>rows.append('<tr><td>RETURN</td><td>'+esc(l.code)+'<div class="small text-secondary">'+esc(l.group)+'</div></td><td>'+Number(l.gas).toFixed(3)+'</td><td>'+Number(l.rate).toFixed(2)+'</td><td>—</td><td>-'+(Number(l.gas)*Number(l.rate)).toFixed(2)+'</td><td><button class="btn btn-sm btn-outline-danger removeReturn" data-i="'+i+'">Remove</button></td></tr>'));
 if(!issueLines.length&&!returnLines.length)rows.append('<tr><td colspan="7" class="text-center text-secondary">No lines selected.</td></tr>');
}

function loadConfig(){
 return $.getJSON(base+'pos/config').done(r=>{
   if(!r.ok){error(r.message);return;}
   const s=$('#transactionType').empty();
   (r.data.transaction_types||[]).forEach(t=>s.append(new Option(String(t).replaceAll('_',' '),t)));
   const def=r.data.default_transaction_type;
   $('#transactionType').val((r.data.transaction_types||[]).includes(def)?def:(r.data.transaction_types||[])[0]);
   $('#method').val(r.data.default_payment_method||'CASH');
 });
}

function loadCylinders(){
 clearError();
 $.getJSON(base+'pos/cylinders',{group_id:$('#group').val()||0,transaction_type:type(),txn_date:$('#txnDate').val()}).done(r=>{
   if(!r.ok){error(r.message);return;}
   const c=$('#cylinders').empty(),groups={};
   (r.data||[]).forEach(x=>{
     groups[x.group_id]=x.group_code+' - '+x.group_name+' ('+x.capacity_kg+' kg)';
     const gas=Number(x.gas_kg);const filled=gas>0;
     c.append(
       '<div class="col-12 col-sm-6 col-lg-4 col-xl-3">' +
       '<div class="border rounded p-3 h-100 cylinder-card">' +
       '<div class="d-flex justify-content-between"><strong>'+esc(x.code)+'</strong><span class="badge text-bg-secondary">'+(filled?(gas>=Number(x.capacity_kg)?'Filled':'Partial'):'Empty')+'</span></div>' +
       '<div class="small text-secondary mb-2">'+esc(x.group_name)+'</div>' +
       '<div>Gas <strong>'+gas.toFixed(3)+'</strong> / '+Number(x.capacity_kg).toFixed(3)+' kg</div>' +
       '<div class="form-check mt-2"><input class="form-check-input pick" type="checkbox" data-id="'+x.id+'"><label class="form-check-label">Select</label></div>' +
       '<div class="mt-2"><label class="form-label small">Gas kg</label><input class="form-control form-control-sm gas" data-id="'+x.id+'" data-code="'+esc(x.code)+'" data-group="'+esc(x.group_name)+'" data-max="'+gas+'" value="'+gas.toFixed(3)+'" inputmode="decimal"></div>' +
       (type()==='GAS_SALE'?
       '<div class="form-check mt-2"><input class="form-check-input sell" type="checkbox" data-id="'+x.id+'"><label class="form-check-label">Sell cylinder</label></div>' : '') +
       '<div class="mt-2"><label class="form-label small">Gas rate</label><input class="form-control form-control-sm rate" data-id="'+x.id+'" value="'+esc(x.gas_rate||'')+'" inputmode="decimal"></div>' +
       '<div class="mt-2"><label class="form-label small">Cylinder price</label><input class="form-control form-control-sm cp" data-id="'+x.id+'" value="'+esc(x.cylinder_price||'')+'" inputmode="decimal"></div>' +
       '</div></div>'
     );
   });
   const $group=$('#group');const old=$group.val();$group.empty().append(new Option('All groups','0'));Object.entries(groups).forEach(a=>$group.append(new Option(a[1],a[0])));if(groups[old])$group.val(old);
   recalc();
 });
}

$('#transactionType').on('change',()=>{issueLines.splice(0);loadCylinders();});
$('#group').on('change',loadCylinders);
$('#txnDate').on('change',loadCylinders);
$('#received').on('input',recalc);
$('#method').on('change',()=>$('#chequeFields').toggleClass('d-none',$('#method').val()!=='CHEQUE'));
$('#customerSearch').on('input',function(){const q=this.value.trim();if(q.length<2)return;$.getJSON(base+'pos/customers',{q},r=>{$('#customer').empty();(r.data||[]).forEach(x=>$('#customer').append(new Option(x.code+' - '+x.name,x.id)));}).always(updateBalanceBar);});
$('#customer').on('change',updateBalanceBar);

$('#cylinders').on('input','.gas',function(){const $i=$(this),max=Number($i.data('max')),v=Number($i.val());if(Number.isFinite(max)&&v>max)$i.val(max.toFixed(3));if(v<0)$i.val('0');recalc();});
$('#cylinders').on('change','.pick,.sell,.rate,.cp',recalc);

$('#save,#savePrint').on('click',function(){
 clearError();const savePrint=this.id==='savePrint';
 const lines=[];
 issueLines.splice(0);
 $('#cylinders .cylinder-card').each(function(){
   const $c=$(this);if(!$c.find('.pick').is(':checked'))return;
   const id=Number($c.find('.pick').data('id'));const gas=type()==='EMPTY_CYLINDER_SALE'?'0.000':String($c.find('.gas').val()||'0');const sell=type()==='GAS_SALE'&&$c.find('.sell').is(':checked');
   const l={cylinder_id:id,type:type()==='EMPTY_CYLINDER_SALE'?'SELL_EMPTY':(sell?'SELL_FILLED':'ISSUE'),gas_kg:gas,rate:String($c.find('.rate').val()||'0'),cylinder_price:String($c.find('.cp').val()||'0'),code:$c.find('.gas').data('code'),group:$c.find('.gas').data('group')};
   issueLines.push({type:l.type,gas:l.gas_kg,rate:l.rate,cp:l.cylinder_price,code:l.code,group:l.group,amount:l.type==='SELL_EMPTY'?Number(l.cylinder_price):(l.type==='SELL_FILLED'?(Number(l.gas_kg)*Number(l.rate)+Number(l.cylinder_price)):Number(l.gas_kg)*Number(l.rate))});
   lines.push(l);
 });
 if(!$('#customer').val()){error('Customer is required.');return;}
 if(!lines.length&&!returnLines.length){error('Select at least one issue/sale cylinder or receive one back.');return;}
 if($('#method').val()==='CASH'&&Number($('#received').val()||0)>0&&!$('#counterId').val()){error('Counter is required for cash.');return;}
 const sold=lines.filter(x=>x.type==='SELL_EMPTY'||x.type==='SELL_FILLED');
 if(sold.length){
   const gas=sold.reduce((n,x)=>n+Number(x.gas_kg||0),0);
   const codes=sold.map(x=>x.code).join(', ');
   if(!confirm(sold.length+' cylinder(s) will leave company stock permanently'+(gas?' with '+gas.toFixed(3)+' kg gas.':'')+'\n\n'+codes+'\n\nContinue?'))return;
 }
 returnLines.forEach(r=>lines.push({cylinder_id:r.id,type:'RETURN',gas_kg:String(r.gas),rate:String(r.rate),cylinder_price:'0'}));
 $('#save,#savePrint').prop('disabled',true);
 $.post(base+'pos',{txn_date:$('#txnDate').val(),transaction_type:type(),customer_id:$('#customer').val(),counter_id:$('#counterId').val(),method:$('#method').val(),received_amount:$('#received').val(),cheque_no:$('#chequeNo').val(),cheque_bank:$('#chequeBank').val(),cheque_date:$('#chequeDate').val(),lines_json:JSON.stringify(lines)})
 .done(r=>{if(!r.ok){error(r.message);return;}lastResult=r.data;alert(r.message+' '+r.data.doc_no);if(savePrint)printReceipt(r.data);issueLines.splice(0);returnLines.splice(0);loadCylinders();recalc();updateBalanceBar();})
 .fail(x=>error(x.responseJSON?.message||'Sale failed.'))
 .always(()=>$('#save,#savePrint').prop('disabled',false));
});

$('#receiveBack').on('click',function(){
 const customer=$('#customer').val();if(!customer){error('Select a customer first.');return;}
 $.getJSON(base+'pos/issued',{customer_id:customer}).done(r=>{
  if(!r.ok){error(r.message);return;}
  const box=$('#returnCards').empty();
  if(!(r.data||[]).length){box.append('<div class="col-12 text-secondary">No cylinders are currently held by this customer.</div>');}
  (r.data||[]).forEach(x=>box.append('<div class="col-12 col-md-6"><div class="border rounded p-3"><div class="form-check"><input class="form-check-input retPick" type="checkbox" data-id="'+x.id+'"><label class="form-check-label fw-semibold">'+esc(x.code)+' — '+esc(x.group_name)+'</label></div><div class="small text-secondary">Gas at return: '+Number(x.gas_kg).toFixed(3)+' kg | Issue rate: '+Number(x.issue_rate||0).toFixed(2)+'</div><div class="row g-2 mt-1"><div class="col-6"><input class="form-control form-control-sm retGas" data-id="'+x.id+'" data-rate="'+esc(x.issue_rate||0)+'" data-code="'+esc(x.code)+'" data-group="'+esc(x.group_name)+'" value="0.000" inputmode="decimal"></div><div class="col-6"><input class="form-control form-control-sm retRate" data-id="'+x.id+'" value="'+esc(x.issue_rate||0)+'" inputmode="decimal"></div></div></div></div>'));
  returnModal.show();
 });
});
$('#addReturns').click(function(){
 returnLines.splice(0);
 $('#returnCards .retPick:checked').each(function(){
   const id=Number($(this).data('id'));const g=$('.retGas[data-id="'+id+'"]'),rate=$('.retRate[data-id="'+id+'"]');
   returnLines.push({id,gas:Number(g.val()||0),rate:Number(rate.val()||0),code:g.data('code'),group:g.data('group')});
 });
 returnModal.hide();recalc();
});
$('#lineRows').on('click','.removeIssue',function(){issueLines.splice(Number($(this).data('i')),1);rebuildPicks();recalc();});
$('#lineRows').on('click','.removeReturn',function(){returnLines.splice(Number($(this).data('i')),1);recalc();});
function rebuildPicks(){ $('#cylinders .pick').prop('checked',false);issueLines.forEach(l=>$('#cylinders .gas[data-code="'+l.code+'"]').closest('.cylinder-card').find('.pick').prop('checked',true));}
$('#clear').click(()=>{location.reload();});

function printReceipt(result){
 const win=window.open('','_blank','width=420,height=700');if(!win)return;
 win.document.write('<html><head><title>'+esc(result.doc_no)+'</title><style>@page{size:80mm auto;margin:3mm}body{font-family:Arial,sans-serif;width:72mm;font-size:12px}h3{text-align:center}.r{display:flex;justify-content:space-between;border-bottom:1px dotted #888;padding:3px 0}.t{font-weight:bold;margin-top:5px}</style></head><body><h3>Pak Gas POS</h3><div class="r"><span>Document</span><span>'+esc(result.doc_no)+'</span></div><div class="r"><span>Net</span><span>'+Number(result.net_amount).toFixed(2)+'</span></div><div class="r"><span>Received</span><span>'+Number(result.received_amount).toFixed(2)+'</span></div><div class="r t"><span>Balance</span><span>'+Number(result.balance_after).toFixed(2)+'</span></div></body></html>');win.document.close();win.focus();win.print();
}
loadConfig().done(loadCylinders);
});
</script>