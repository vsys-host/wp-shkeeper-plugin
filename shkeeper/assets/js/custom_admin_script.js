jQuery(document).ready(function() {
	jQuery('<option>').val('mark_invoiced').text(SHKEEPER.invoicedStatus).appendTo("select[name='action']");
	jQuery('<option>').val('mark_invoiced').text(SHKEEPER.invoicedStatus).appendTo("select[name='action2']");
	jQuery('<option>').val('mark_partial').text(SHKEEPER.partialStatus).appendTo("select[name='action']");
	jQuery('<option>').val('mark_partial').text(SHKEEPER.partialStatus).appendTo("select[name='action2']");
});
