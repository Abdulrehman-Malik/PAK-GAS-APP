<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h1 class="h3 mb-1">Sales History</h1><p class="text-secondary mb-0">Posted and void sales with linked receipt details.</p></div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-md-2"><input id="from" type="date" class="form-control" aria-label="From"></div>
            <div class="col-md-2"><input id="to" type="date" class="form-control" aria-label="To"></div>
            <div class="col-md-3"><input id="search" class="form-control" placeholder="Doc no, customer code or name"></div>
            <div class="col-md-2"><select id="status" class="form-select"><option value="">All status</option><option>POSTED</option><option>VOID</option></select></div>
            <div class="col-md-2"><button id="refresh" class="btn btn-outline-primary w-100">Refresh</button></div>
        </div>
        <div id="msg"></div>
        <div class="table-responsive"><table class="table table-sm align-middle" id="salesTable">
            <thead><tr><th>Doc</th><th>Date</th><th>Customer</th><th>Lines</th><th>Net</th><th>Received</th><th>Balance</th><th>Status</th><th></th></tr></thead>
            <tbody></tbody>
        </table></div>
    </div>
</div>
<div class="modal fade" id="detailModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
<div class="modal-header"><h5 class="modal-title">Sale Detail</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body" id="detailBody"></div></div></div></div>
<script>
$(function(){
 const base=<?=json_encode(url('/'),JSON_THROW_ON_ERROR)?>;
 const today=<?=json_encode($today??date('Y-m-d'))?>;
 $('#from').val(today);$('#to').val(today);
 function esc(v){return $('<div>').text(v??'').html();}
 function load(){
  $.getJSON(base+'sales/data',{from:$('#from').val(),to:$('#to').val(),search:$('#search').val(),status:$('#status').val()})
   .done(function(r){let b=$('#salesTable tbody').empty();(r.data.rows||[]).forEach(function(x){
    b.append('<tr><td>'+esc(x.doc_no)+'</td><td>'+esc(x.txn_date)+'</td><td>'+esc(x.customer_code+' - '+x.customer_name)+'</td><td>'+esc(x.line_count)+'</td><td>'+Number(x.net_amount).toFixed(2)+'</td><td>'+Number(x.received_amount).toFixed(2)+'</td><td>'+Number(x.balance_after).toFixed(2)+'</td><td>'+esc(x.status)+'</td><td class="text-end"><button class="btn btn-sm btn-outline-secondary detail" data-id="'+x.id+'">View</button> '+(x.status==='POSTED'?' <button class="btn btn-sm btn-outline-danger void" data-id="'+x.id+'" data-doc="'+esc(x.doc_no)+'">Void</button>':'')+'</td></tr>');
   });}).fail(function(x){$('#msg').html('<div class="alert alert-danger">'+esc(x.responseJSON?.message||'Unable to load sales.')+'</div>');});
 }
 $(document).on('click','.detail',function(){
  $.getJSON(base+'sales/'+$(this).data('id')).done(function(r){
   let s=r.data.sale,b='<div><strong>'+esc(s.doc_no)+'</strong> · '+esc(s.txn_date)+' · '+esc(s.customer_code+' - '+s.customer_name)+'</div><hr><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Code</th><th>Type</th><th>Gas</th><th>Rate</th><th>Cyl. Price</th><th>Amount</th></tr></thead><tbody>';
   (r.data.lines||[]).forEach(function(l){b+='<tr><td>'+esc(l.code)+'</td><td>'+esc(l.line_type)+'</td><td>'+Number(l.gas_kg).toFixed(3)+'</td><td>'+Number(l.rate).toFixed(2)+'</td><td>'+Number(l.cylinder_price).toFixed(2)+'</td><td>'+Number(l.amount).toFixed(2)+'</td></tr>';});
   b+='</tbody></table></div><div class="text-end"><strong>Net '+Number(s.net_amount).toFixed(2)+'</strong><br>Received '+Number(s.received_amount).toFixed(2)+'<br>Balance '+Number(s.balance_after).toFixed(2)+'</div>';
   if(r.data.receipt)b+='<hr>Receipt: '+esc(r.data.receipt.doc_no)+' · Source: '+esc(r.data.receipt.source)+' · '+esc(r.data.receipt.status);
   $('#detailBody').html(b); bootstrap.Modal.getOrCreateInstance(document.getElementById('detailModal')).show();
  });
 });
 $(document).on('click','.void',function(){let id=$(this).data('id'),doc=$(this).data('doc'),reason=prompt('Void reason for '+doc+':');if(!reason)return;$.post(base+'sales/'+id+'/void',{reason:reason}).done(function(r){alert(r.message);load();}).fail(function(x){alert(x.responseJSON?.message||'Void failed');});});
 $('#refresh').on('click',load);$('#search').on('keyup',function(e){if(e.key==='Enter')load();});load();
});
</script>