(function( $ ) {
	'use strict';

	const scopeRows = $('#stwi-scope-rows');

	function nextIndex() {
		const rows = scopeRows.find('tr.stwi-scope-row');
		if (!rows.length) {
			return 0;
		}
		let max = -1;
		rows.each(function(){
			const name = $(this).find('input:first').attr('name'); // import_scope[0][post_type]
			const match = name && name.match(/import_scope\[(\d+)\]/);
			if (match && parseInt(match[1], 10) > max) {
				max = parseInt(match[1], 10);
			}
		});
		return max + 1;
	}

	function addScopeRow(postType = '', taxonomies = '') {
		const idx = nextIndex();
		const row = $(`
			<tr class="stwi-scope-row">
				<td><input type="text" name="import_scope[${idx}][post_type]" value="${postType}" placeholder="e.g. events-cpt"></td>
				<td><input type="text" name="import_scope[${idx}][taxonomies]" value="${taxonomies}" placeholder="e.g. event-type,industry,topic"><p class="description">Use slugs, comma-separated.</p></td>
				<td><button type="button" class="button-link stwi-remove-row">Remove</button></td>
			</tr>
		`);
		scopeRows.append(row);
	}

	$(document).on('click', '.stwi-add-row', function(e){
		e.preventDefault();
		addScopeRow();
	});

	$(document).on('click', '.stwi-remove-row', function(e){
		e.preventDefault();
		$(this).closest('tr').remove();
	});

	function ajaxAction(action, onSuccess) {
		const data = {
			action: action,
			nonce: stwiAdmin.nonce
		};
		$.post(stwiAdmin.ajaxUrl, data)
			.done(function(response){
				if (response.success) {
					alert(response.data && response.data.message ? response.data.message : 'Success');
					if (onSuccess) {
						onSuccess(response.data);
					}
				} else {
					alert((response.data && response.data.message) ? response.data.message : 'Request failed.');
				}
			})
			.fail(function(){
				alert('AJAX request failed. Check console/logs.');
			});
	}

	$('#stwi-test-connection').on('click', function(e){
		e.preventDefault();
		ajaxAction('stwi_test_connection');
	});

	$('#stwi-start-import').on('click', function(e){
		e.preventDefault();
		ajaxAction('stwi_start_import');
	});

	$('#stwi-stop-import').on('click', function(e){
		e.preventDefault();
		ajaxAction('stwi_stop_import');
	});

	$('#stwi-run-once').on('click', function(e){
		e.preventDefault();
		ajaxAction('stwi_run_batch_now');
	});

	$('#stwi-refresh-log').on('click', function(e){
		e.preventDefault();
		const data = {
			action: 'stwi_fetch_logs',
			nonce: stwiAdmin.nonce
		};
		$.post(stwiAdmin.ajaxUrl, data)
			.done(function(response){
				if (response.success && response.data && response.data.log !== undefined) {
					$('#stwi-log-viewer').val(response.data.log);
				} else {
					alert('Could not load log.');
				}
			})
			.fail(function(){
				alert('AJAX request failed. Check console/logs.');
			});
	});

})( jQuery );
